-- CreateSchema
CREATE SCHEMA IF NOT EXISTS "public";

-- CreateEnum
CREATE TYPE "InstanceState" AS ENUM ('CREATED', 'STARTING', 'QR_REQUIRED', 'PAIRING_CODE_REQUIRED', 'PAIRING', 'AUTHENTICATED', 'SYNCING', 'READY', 'DISCONNECTED', 'RECONNECT_WAIT', 'PAUSED', 'LOGGED_OUT', 'REPLACED', 'RESTRICTED', 'ERROR', 'STOPPED');

-- CreateEnum
CREATE TYPE "MessageStatus" AS ENUM ('ACCEPTED', 'SENT', 'SERVER_ACK', 'DELIVERED', 'READ', 'PLAYED', 'FAILED');

-- CreateEnum
CREATE TYPE "WebhookStatus" AS ENUM ('PENDING', 'DELIVERING', 'DELIVERED', 'RETRY_WAIT', 'DEAD_LETTER');

-- CreateTable
CREATE TABLE "instances" (
    "id" TEXT NOT NULL,
    "tenantReference" TEXT NOT NULL,
    "externalInstanceId" TEXT NOT NULL,
    "displayName" TEXT,
    "phoneNumber" TEXT,
    "state" "InstanceState" NOT NULL DEFAULT 'CREATED',
    "authStatus" TEXT,
    "connectionStatus" TEXT,
    "lastConnectedAt" TIMESTAMP(3),
    "lastDisconnectedAt" TIMESTAMP(3),
    "lastQr" TEXT,
    "lastQrAt" TIMESTAMP(3),
    "qrExpiresAt" TIMESTAMP(3),
    "pairingCode" TEXT,
    "pairingCodeExpiresAt" TIMESTAMP(3),
    "lastErrorCode" TEXT,
    "lastErrorMessage" TEXT,
    "reconnectAttempts" INTEGER NOT NULL DEFAULT 0,
    "reconnectAfter" TIMESTAMP(3),
    "reconnectWindowStartedAt" TIMESTAMP(3),
    "baileysPackage" TEXT,
    "readySince" TIMESTAMP(3),
    "proxyEnabled" BOOLEAN NOT NULL DEFAULT false,
    "proxyType" TEXT,
    "proxyHost" TEXT,
    "proxyPort" INTEGER,
    "proxyUsernameEnc" TEXT,
    "proxyPasswordEnc" TEXT,
    "webhookUrl" TEXT,
    "webhookSecretEnc" TEXT,
    "enabled" BOOLEAN NOT NULL DEFAULT true,
    "ownerNodeId" TEXT,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "instances_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "instance_events" (
    "id" TEXT NOT NULL,
    "instanceId" TEXT NOT NULL,
    "type" TEXT NOT NULL,
    "fromState" TEXT,
    "toState" TEXT,
    "reason" TEXT,
    "detail" JSONB,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "instance_events_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "auth_credentials" (
    "id" TEXT NOT NULL,
    "instanceId" TEXT NOT NULL,
    "credentialsEnc" TEXT NOT NULL,
    "baileysPackage" TEXT NOT NULL,
    "baileysVersion" TEXT NOT NULL,
    "lastWriteAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "auth_credentials_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "auth_keys" (
    "id" TEXT NOT NULL,
    "instanceId" TEXT NOT NULL,
    "category" TEXT NOT NULL,
    "keyId" TEXT NOT NULL,
    "valueEnc" TEXT NOT NULL,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "auth_keys_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "gateway_messages" (
    "id" TEXT NOT NULL,
    "instanceId" TEXT NOT NULL,
    "idempotencyKey" TEXT NOT NULL,
    "clientMessageId" TEXT,
    "recipient" TEXT NOT NULL,
    "kind" TEXT NOT NULL,
    "payloadHash" TEXT NOT NULL,
    "status" "MessageStatus" NOT NULL DEFAULT 'ACCEPTED',
    "whatsappMessageId" TEXT,
    "acceptedAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "sentAt" TIMESTAMP(3),
    "serverAckAt" TIMESTAMP(3),
    "deliveredAt" TIMESTAMP(3),
    "readAt" TIMESTAMP(3),
    "failedAt" TIMESTAMP(3),
    "failureReason" TEXT,
    "metadata" JSONB,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "gateway_messages_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "polls" (
    "id" TEXT NOT NULL,
    "instanceId" TEXT NOT NULL,
    "whatsappPollMessageId" TEXT,
    "clientMessageId" TEXT,
    "recipient" TEXT NOT NULL,
    "question" TEXT NOT NULL,
    "options" JSONB NOT NULL,
    "selectableCount" INTEGER NOT NULL DEFAULT 1,
    "encPayload" TEXT,
    "metadata" JSONB,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "polls_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "poll_votes" (
    "id" TEXT NOT NULL,
    "pollId" TEXT NOT NULL,
    "voterJid" TEXT NOT NULL,
    "selectedOptionIndexes" JSONB NOT NULL,
    "selectedOptions" JSONB NOT NULL,
    "changeType" TEXT NOT NULL DEFAULT 'received',
    "fingerprint" TEXT NOT NULL,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "poll_votes_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "webhook_events" (
    "id" TEXT NOT NULL,
    "instanceId" TEXT,
    "eventType" TEXT NOT NULL,
    "eventVersion" TEXT NOT NULL DEFAULT '1.0',
    "payload" JSONB NOT NULL,
    "metadata" JSONB,
    "status" "WebhookStatus" NOT NULL DEFAULT 'PENDING',
    "attempts" INTEGER NOT NULL DEFAULT 0,
    "nextAttemptAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "deliveredAt" TIMESTAMP(3),
    "lastStatusCode" INTEGER,
    "lastError" TEXT,
    "occurredAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "webhook_events_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "request_nonces" (
    "nonce" TEXT NOT NULL,
    "seenAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "request_nonces_pkey" PRIMARY KEY ("nonce")
);

-- CreateIndex
CREATE UNIQUE INDEX "instances_externalInstanceId_key" ON "instances"("externalInstanceId");

-- CreateIndex
CREATE INDEX "instances_state_idx" ON "instances"("state");

-- CreateIndex
CREATE INDEX "instances_tenantReference_idx" ON "instances"("tenantReference");

-- CreateIndex
CREATE INDEX "instance_events_instanceId_createdAt_idx" ON "instance_events"("instanceId", "createdAt");

-- CreateIndex
CREATE UNIQUE INDEX "auth_credentials_instanceId_key" ON "auth_credentials"("instanceId");

-- CreateIndex
CREATE INDEX "auth_keys_instanceId_category_idx" ON "auth_keys"("instanceId", "category");

-- CreateIndex
CREATE UNIQUE INDEX "auth_keys_instanceId_category_keyId_key" ON "auth_keys"("instanceId", "category", "keyId");

-- CreateIndex
CREATE UNIQUE INDEX "gateway_messages_idempotencyKey_key" ON "gateway_messages"("idempotencyKey");

-- CreateIndex
CREATE INDEX "gateway_messages_instanceId_status_idx" ON "gateway_messages"("instanceId", "status");

-- CreateIndex
CREATE INDEX "gateway_messages_whatsappMessageId_idx" ON "gateway_messages"("whatsappMessageId");

-- CreateIndex
CREATE UNIQUE INDEX "polls_whatsappPollMessageId_key" ON "polls"("whatsappPollMessageId");

-- CreateIndex
CREATE INDEX "polls_instanceId_idx" ON "polls"("instanceId");

-- CreateIndex
CREATE INDEX "poll_votes_pollId_idx" ON "poll_votes"("pollId");

-- CreateIndex
CREATE UNIQUE INDEX "poll_votes_pollId_voterJid_fingerprint_key" ON "poll_votes"("pollId", "voterJid", "fingerprint");

-- CreateIndex
CREATE INDEX "webhook_events_status_nextAttemptAt_idx" ON "webhook_events"("status", "nextAttemptAt");

-- CreateIndex
CREATE INDEX "webhook_events_instanceId_eventType_idx" ON "webhook_events"("instanceId", "eventType");

-- CreateIndex
CREATE INDEX "request_nonces_seenAt_idx" ON "request_nonces"("seenAt");

-- AddForeignKey
ALTER TABLE "instance_events" ADD CONSTRAINT "instance_events_instanceId_fkey" FOREIGN KEY ("instanceId") REFERENCES "instances"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "auth_credentials" ADD CONSTRAINT "auth_credentials_instanceId_fkey" FOREIGN KEY ("instanceId") REFERENCES "instances"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "auth_keys" ADD CONSTRAINT "auth_keys_instanceId_fkey" FOREIGN KEY ("instanceId") REFERENCES "instances"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "gateway_messages" ADD CONSTRAINT "gateway_messages_instanceId_fkey" FOREIGN KEY ("instanceId") REFERENCES "instances"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "polls" ADD CONSTRAINT "polls_instanceId_fkey" FOREIGN KEY ("instanceId") REFERENCES "instances"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "poll_votes" ADD CONSTRAINT "poll_votes_pollId_fkey" FOREIGN KEY ("pollId") REFERENCES "polls"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "webhook_events" ADD CONSTRAINT "webhook_events_instanceId_fkey" FOREIGN KEY ("instanceId") REFERENCES "instances"("id") ON DELETE SET NULL ON UPDATE CASCADE;

