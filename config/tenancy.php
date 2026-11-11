<?php

return [
    /*
    | Tenants live on subdomains of this domain: acme.yourapp.in. Requests to the
    | bare central domain are platform requests (signup, super-admin).
    */
    'central_domain' => env('TENANCY_CENTRAL_DOMAIN', 'yourapp.test'),

    /*
    | Allow the X-Tenant header to pick the tenant when the host is not a tenant
    | subdomain (local dev through the Vite proxy, or path-based fallback hosting).
    */
    'allow_header' => (bool) env('TENANCY_ALLOW_HEADER', true),

    /* SMS driver for kaarigar OTP: "log" (dev) or a provider added later (msg91, …). */
    'sms_driver' => env('SMS_DRIVER', 'log'),

    /* Return the OTP in the API response — only ever true for local development. */
    'expose_otp' => (bool) env('KAARIGAR_EXPOSE_OTP', false),
];
