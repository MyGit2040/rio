# Release guard: WhatsApp gateway changes

Treat any change to the WhatsApp gateway as incomplete until all of the
following are verified against the deployed runtime:

1. The configured gateway health endpoint responds successfully.
2. The tenant configuration resolves to the intended gateway URL, API key, and
   session name.
3. The gateway session exists, can start idempotently, and reports its real
   connection state.
4. Eagle can retrieve a non-empty QR payload and persist it on the matching
   device record.
5. The QR is rendered in the Devices UI and a real WhatsApp account completes
   the scan.
6. A connected session passes a real text-message smoke test before bulk
   campaigns are enabled.

Never assume similarly named OpenWA images share an API contract. Verify the
runtime's documented API version and map internal session UUIDs separately from
human-readable session names.
