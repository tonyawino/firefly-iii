FROM fireflyiii/core@sha256:a2b53539338d1cd04d341b229c3806cb178cf64c72459060e3ed34bec4167bd8

ARG DERIVATIVE_COMMIT
LABEL org.opencontainers.image.source="https://github.com/tonyawino/firefly-iii" \
      org.opencontainers.image.revision="${DERIVATIVE_COMMIT}" \
      org.opencontainers.image.base.name="fireflyiii/core" \
      org.opencontainers.image.base.digest="sha256:a2b53539338d1cd04d341b229c3806cb178cf64c72459060e3ed34bec4167bd8" \
      org.opencontainers.image.title="Firefly III Finance Hub private bootstrap derivative"

COPY --chown=www-data:www-data bootstrap/app.php /var/www/html/bootstrap/app.php
COPY --chown=www-data:www-data routes/api.php /var/www/html/routes/api.php
COPY --chown=www-data:www-data config/finance-hub.php /var/www/html/config/finance-hub.php
COPY --chown=www-data:www-data app/Http/Middleware/AuthenticateFinanceHubProvisioner.php /var/www/html/app/Http/Middleware/AuthenticateFinanceHubProvisioner.php
COPY --chown=www-data:www-data app/Http/Controllers/FinanceHub/ProvisioningController.php /var/www/html/app/Http/Controllers/FinanceHub/ProvisioningController.php
COPY --chown=www-data:www-data app/Services/FinanceHub/ProvisioningService.php /var/www/html/app/Services/FinanceHub/ProvisioningService.php
COPY --chown=www-data:www-data app/Models/FinanceHub/ProvisioningOperation.php app/Models/FinanceHub/ProvisioningState.php /var/www/html/app/Models/FinanceHub/
COPY --chown=www-data:www-data database/migrations/2026_10_15_000000_create_finance_hub_provisioning_tables.php /var/www/html/database/migrations/2026_10_15_000000_create_finance_hub_provisioning_tables.php
