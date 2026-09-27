# Administrator access control

Nexora Commerce uses server-side RBAC. Menu visibility is only a UX layer; every mapped admin route is authorized on the server and unknown admin routes are denied to restricted users.

Built-in roles:
- ROLE_SUPER_ADMIN — full access.
- ROLE_MANAGER — day-to-day catalog, orders and commerce operations.
- ROLE_EDITOR — catalog/content/appearance editing without critical system access.
- ROLE_SUPPORT — orders/customers/support operations.
- ROLE_VIEWER — safe read-only preset.

Custom roles can be created in Admin → System → Administrators and permissions.

Permissions cover view/manage/delete/export/bulk/refund, media, search, marketing, feeds, notifications, appearance, system settings, cron, recovery, updates, extensions and integrations. `personal_data.view` separately controls display of customer email/phone/address data.

Store scope is assigned per administrator. A restricted administrator without a matching store scope is denied. Super administrators bypass store scope.

Safety rules:
- the last active super administrator cannot be disabled or stripped of ROLE_SUPER_ADMIN;
- system roles cannot be deleted;
- critical actions remain protected by CSRF and server-side permission checks;
- unknown new admin routes fail closed for non-super-admin users.
