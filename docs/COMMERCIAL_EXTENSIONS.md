# Commercial extensions

Nexora Core remains usable without a paid runtime dependency. Individual extensions may be free, one-time paid, subscription-based, or externally licensed.

Manifest metadata:

```json
"commercial": {
  "model": "subscription",
  "product_id": "vendor.module.pro",
  "license_url": "https://vendor.example/license",
  "manage_url": "https://vendor.example/account",
  "trial_days": 14
}
```

`remote_app` does not download executable PHP from the developer server. A small connector package is installed in Nexora; its `remote.base_url` points to the external HTTPS service. API keys/OAuth tokens authenticate requests and must be stored as secret settings.

For `trusted_release`, executable code is inside the signed ZIP. Commercial entitlement may be checked by the module, but temporary vendor-server downtime must not corrupt Core or unrelated extensions. The module must fail closed for its own paid functionality and fail open for the store.
