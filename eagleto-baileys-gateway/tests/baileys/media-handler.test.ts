import { randomUUID } from 'node:crypto'
import { readdir, rm, stat, utimes, writeFile, mkdir } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

import type { Logger } from 'pino'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'

import type { BaileysSocketHandle } from '../../src/baileys/adapter/types.js'
import {
  MediaHandler,
  MediaRejectedError,
  extensionForMime,
  mediaSignature,
  signMediaUrl,
  verifyMediaUrlSignature,
} from '../../src/baileys/media-handler.js'
import { setEnvForTesting } from '../../src/config/env.js'
import { testEnv } from '../helpers/env-fixture.js'

const INSTANCE_ID = 'inst-abc123'
const MESSAGE_ID = 'ABCD1234567890'

let mediaRoot: string

/**
 * Only `downloadMedia` is implemented — it is the sole socket capability the
 * handler uses, and stubbing the rest would test the stub, not the handler.
 */
function fakeSocket(buffer: Buffer): BaileysSocketHandle {
  return {
    downloadMedia: async () => buffer,
  } as unknown as BaileysSocketHandle
}

/**
 * Injected so the tests never build the real pino logger: it would spin up a
 * pino-pretty worker thread per test outside production.
 */
const silentLogger = {
  debug: () => {},
  info: () => {},
  warn: () => {},
  error: () => {},
} as unknown as Logger

function handler(): MediaHandler {
  return new MediaHandler(silentLogger)
}

beforeEach(() => {
  mediaRoot = join(tmpdir(), `eagleto-media-${randomUUID()}`)

  setEnvForTesting(
    testEnv({
      MEDIA_STORAGE_PATH: mediaRoot,
      MEDIA_PUBLIC_BASE_URL: 'https://gateway.test/media',
      MAX_MEDIA_SIZE_MB: '1',
      TEMP_MEDIA_RETENTION_MINUTES: '60',
    }),
  )
})

afterEach(async () => {
  await rm(mediaRoot, { recursive: true, force: true })
  setEnvForTesting(null)
})

describe('extensionForMime', () => {
  it('maps known types and defaults to .bin', () => {
    expect(extensionForMime('image/jpeg')).toBe('.jpg')
    expect(extensionForMime('application/pdf')).toBe('.pdf')
    expect(extensionForMime('application/x-unheard-of')).toBe('.bin')
  })

  it('ignores MIME parameters, as sent on voice notes', () => {
    expect(extensionForMime('audio/ogg; codecs=opus')).toBe('.ogg')
  })
})

describe('MediaHandler.storeInbound', () => {
  it('writes an allowed file and returns a descriptor carrying no bytes', async () => {
    const content = Buffer.from('PNG-CONTENT-SENTINEL-0123456789', 'utf8')
    const descriptor = await handler().storeInbound({
      instanceId: INSTANCE_ID,
      whatsappMessageId: MESSAGE_ID,
      message: { key: MESSAGE_ID },
      socket: fakeSocket(content),
      mimeType: 'image/png',
      fileName: 'holiday photo.png',
      caption: 'from the beach',
    })

    expect(descriptor.mimeType).toBe('image/png')
    expect(descriptor.sizeBytes).toBe(content.length)
    expect(descriptor.fileName).toBe('holiday photo.png')
    expect(descriptor.caption).toBe('from the beach')
    expect(descriptor.sha256).toMatch(/^[0-9a-f]{64}$/)

    // The bytes live on disk under the content hash, never under the
    // sender-supplied file name.
    const written = await readdir(join(mediaRoot, INSTANCE_ID))
    expect(written).toEqual([`${descriptor.sha256}.png`])

    const onDisk = await stat(join(mediaRoot, INSTANCE_ID, `${descriptor.sha256}.png`))
    expect(onDisk.size).toBe(content.length)

    // Nothing that could be a payload: no buffer, no base64, no disk path.
    const serialised = JSON.stringify(descriptor)
    expect(serialised).not.toContain('SENTINEL')
    expect(serialised).not.toContain(content.toString('base64'))
    expect(serialised).not.toContain(mediaRoot)
    expect(Object.keys(descriptor).sort()).toEqual([
      'caption',
      'downloadUrl',
      'expiresAt',
      'fileName',
      'mimeType',
      'sha256',
      'sizeBytes',
    ])
  })

  it('issues a download URL that verifies, and an expiry matching retention', async () => {
    const descriptor = await handler().storeInbound({
      instanceId: INSTANCE_ID,
      whatsappMessageId: MESSAGE_ID,
      message: {},
      socket: fakeSocket(Buffer.from('pdf bytes')),
      mimeType: 'application/pdf',
    })

    const url = new URL(descriptor.downloadUrl)
    const relativePath = `${INSTANCE_ID}/${descriptor.sha256}.pdf`

    expect(url.origin + url.pathname).toBe(`https://gateway.test/media/${relativePath}`)

    const expires = Number(url.searchParams.get('expires'))
    const signature = url.searchParams.get('signature') ?? ''

    expect(verifyMediaUrlSignature(relativePath, expires, signature)).toBe(true)
    expect(new Date(descriptor.expiresAt).getTime()).toBe(expires * 1000)

    // 60 minutes of retention, allowing a couple of seconds of test runtime.
    const secondsAhead = expires - Math.floor(Date.now() / 1000)
    expect(secondsAhead).toBeGreaterThan(60 * 60 - 10)
    expect(secondsAhead).toBeLessThanOrEqual(60 * 60)
  })

  it('rejects a buffer over the size limit', async () => {
    const oversize = Buffer.alloc(2 * 1024 * 1024, 1) // limit is 1 MB

    await expect(
      handler().storeInbound({
        instanceId: INSTANCE_ID,
        whatsappMessageId: MESSAGE_ID,
        message: {},
        socket: fakeSocket(oversize),
        mimeType: 'image/jpeg',
      }),
    ).rejects.toThrow(MediaRejectedError)

    // Nothing was written for a file we refused.
    await expect(readdir(join(mediaRoot, INSTANCE_ID))).rejects.toThrow()
  })

  it('rejects a disallowed MIME type before downloading anything', async () => {
    let downloadCalled = false
    const socket = {
      downloadMedia: async () => {
        downloadCalled = true
        return Buffer.from('malware')
      },
    } as unknown as BaileysSocketHandle

    await expect(
      handler().storeInbound({
        instanceId: INSTANCE_ID,
        whatsappMessageId: MESSAGE_ID,
        message: {},
        socket,
        mimeType: 'application/x-msdownload',
        fileName: 'invoice.exe',
      }),
    ).rejects.toMatchObject({ reason: 'disallowed_mime' })

    expect(downloadCalled).toBe(false)
  })

  it('accepts a voice note whose MIME carries codec parameters', async () => {
    const descriptor = await handler().storeInbound({
      instanceId: INSTANCE_ID,
      whatsappMessageId: MESSAGE_ID,
      message: {},
      socket: fakeSocket(Buffer.from('ogg bytes')),
      mimeType: 'audio/ogg; codecs=opus',
    })

    expect(descriptor.mimeType).toBe('audio/ogg')
    expect(descriptor.downloadUrl).toContain('.ogg')
  })

  it('refuses an instance id that is not a safe path segment', async () => {
    await expect(
      handler().storeInbound({
        instanceId: '../../etc',
        whatsappMessageId: MESSAGE_ID,
        message: {},
        socket: fakeSocket(Buffer.from('x')),
        mimeType: 'image/png',
      }),
    ).rejects.toMatchObject({ reason: 'invalid_instance_id' })
  })
})

describe('media URL signatures', () => {
  const path = `${INSTANCE_ID}/deadbeef.pdf`

  it('verifies a signature it just produced', () => {
    const expires = Math.floor(Date.now() / 1000) + 600

    expect(verifyMediaUrlSignature(path, expires, mediaSignature(path, expires))).toBe(true)
  })

  it('fails when the path is tampered with', () => {
    const expires = Math.floor(Date.now() / 1000) + 600
    const signature = mediaSignature(path, expires)

    expect(verifyMediaUrlSignature(`${INSTANCE_ID}/other.pdf`, expires, signature)).toBe(false)
    // Swapping in another tenant's directory must not verify either.
    expect(verifyMediaUrlSignature(`other-instance/deadbeef.pdf`, expires, signature)).toBe(false)
  })

  it('fails when the expiry is edited to buy more time', () => {
    const expires = Math.floor(Date.now() / 1000) + 600
    const signature = mediaSignature(path, expires)

    expect(verifyMediaUrlSignature(path, expires + 3600, signature)).toBe(false)
  })

  it('fails once expired, even with a genuine signature', () => {
    const expired = Math.floor(Date.now() / 1000) - 60
    const signature = mediaSignature(path, expired)

    expect(verifyMediaUrlSignature(path, expired, signature)).toBe(false)
    // ...and would have verified while still live.
    expect(verifyMediaUrlSignature(path, expired, signature, (expired - 10) * 1000)).toBe(true)
  })

  it('rejects malformed signatures without comparing', () => {
    const expires = Math.floor(Date.now() / 1000) + 600

    expect(verifyMediaUrlSignature(path, expires, '')).toBe(false)
    expect(verifyMediaUrlSignature(path, expires, 'not-hex')).toBe(false)
    expect(verifyMediaUrlSignature(path, 0, mediaSignature(path, 0))).toBe(false)
  })

  it('builds a URL whose query parameters match the signature', () => {
    const expires = Math.floor(Date.now() / 1000) + 600
    const url = new URL(signMediaUrl(path, expires))

    expect(url.searchParams.get('expires')).toBe(String(expires))
    expect(url.searchParams.get('signature')).toBe(mediaSignature(path, expires))
  })
})

describe('MediaHandler.pruneExpired', () => {
  it('returns 0 rather than throwing when the media directory does not exist', async () => {
    // mediaRoot is only created on first write; nothing has been stored.
    await expect(handler().pruneExpired()).resolves.toBe(0)
  })

  it('deletes files past the retention window and keeps fresh ones', async () => {
    const directory = join(mediaRoot, INSTANCE_ID)
    await mkdir(directory, { recursive: true })

    const stale = join(directory, 'stale.pdf')
    const fresh = join(directory, 'fresh.pdf')
    await writeFile(stale, 'old')
    await writeFile(fresh, 'new')

    // Age the first file past the 60 minute retention window.
    const twoHoursAgo = new Date(Date.now() - 2 * 60 * 60 * 1000)
    await utimes(stale, twoHoursAgo, twoHoursAgo)

    expect(await handler().pruneExpired()).toBe(1)
    expect(await readdir(directory)).toEqual(['fresh.pdf'])
  })
})
