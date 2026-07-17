FROM openwa/wa-automate:latest

USER root

# OpenWA's session watchdog invokes `ps`; the upstream image does not include
# it, causing the runtime to exit immediately after the WhatsApp Web page loads.
RUN apt-get update \
    && apt-get install -y --no-install-recommends procps \
    && rm -rf /var/lib/apt/lists/*
