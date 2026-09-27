# Administration RBAC / contextual policies

Nexora Commerce 2.9.0 adds a permission layer above Symfony authentication.

`mc_admin_permission` contains explicit capabilities. `mc_admin_role_permission` maps roles to capabilities. `mc_admin_store_scope` restricts a non-super-admin to selected stores. `ROLE_SUPER_ADMIN` remains the installer-created break-glass administrator and bypasses database permission lookups.

The request subscriber maps sensitive admin routes to permissions such as catalog management, order management/refunds, customer access, content, appearance, extensions, recovery and Core update. Authorization infrastructure fails closed for non-super-admin users.

This is deliberately simpler than a generic ABAC language. Context is evaluated as user + permission + store/resource. Additional resource policies can be added through dedicated voters without turning ordinary store administration into a policy-programming task.
