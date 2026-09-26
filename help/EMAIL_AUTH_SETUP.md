# Email registration setup

Email verification requires an SMTP account. Keep these values in the deployment `.env` file or secret store; never commit real credentials.

```ini
[EMAIL]
host = smtp.example.com
port = 587
username = mall@example.com
password = replace-with-provider-app-password
from = mall@example.com
from_name = 苏迪商城
encryption = tls
timeout = 10
```

Use a provider app password and TLS on port 587, or implicit TLS on port 465 (`encryption = ssl`). SMTP auth without encryption is rejected. Before enabling email registration on an existing installation, take the normal database backup and apply `crmeb/public/install/sudi_email_auth_migration.sql`; it adds a nullable unique email column and widens the password-hash column without changing existing user records. Run the migration before deploying code that queries the email column.

The CI SMTP fixture is local-only and does not deliver real email. Production verification requires sending a code to an operator-controlled mailbox after SMTP credentials are configured.
