# Trusted order hook example

Generate a current trusted skeleton instead of copying an old signature:

    php bin/console commerce:extension:scaffold example.order_hook --name="Order Hook" --preset=trusted-route

Declare `order.created` in `events`, add a permission for any admin route, implement the generated Entrypoint, pack, sign, then validate/test the final ZIP. Private signing keys must never be stored in the module source tree.
