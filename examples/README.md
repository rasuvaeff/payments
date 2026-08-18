# Examples

| Script | Shows | Needs server? |
|---|---|---|
| `build-json-request.php` | JSON request encoding and bearer authentication | No |
| `process-webhook.php` | Validation, replay claim and durable queue acceptance | No |

Run from the package directory after `composer install`:

```bash
php examples/build-json-request.php
php examples/process-webhook.php
```
