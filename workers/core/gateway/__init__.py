"""Inbound HTTPS gateway: FreeSaaS instances call /v1/<service>/* on this host.

One port, one TLS cert (SPKI pin), keyed HMAC clients, IP allowlist and bans for
all plugins. Plugins expose plain handlers on 127.0.0.1 (see local_api.py).
"""
