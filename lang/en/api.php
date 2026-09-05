<?php

declare(strict_types=1);

return [
    'errors' => [
        'titles' => [
            'not_found' => 'Resource Not Found',
            'unauthorized' => 'Unauthorized',
            'forbidden' => 'Forbidden',
            'too_many_requests' => 'Too Many Requests',
            'unsupported_media_type' => 'Unsupported Media Type',
            'method_not_allowed' => 'Method Not Allowed',
            'page_expired' => 'Page Expired',
            'internal_server_error' => 'Internal Server Error',
            'validation_failed' => 'Validation Failed',
        ],

        'http' => [
            'not_found' => 'The requested resource could not be found or has been moved.',
            'unauthorized' => 'Authentication is required to access this resource.',
            'forbidden_access' => 'You do not have the required permissions for this action.',
            'too_many_requests' => 'You have exceeded the allowed number of requests. Please try again in :seconds seconds.',
            'unsupported_media_type' => 'Unsupported media type. Use application/json request bodies.',

            'method_not_allowed' => 'The :method method is not supported for this route.',
            'page_expired' => 'Your session has expired. Please refresh the page and try again.',
            'internal_server_error' => 'An unexpected error occurred. Please try again later.',
        ],

        'validation' => [
            'failed' => 'The provided data was invalid. Check the "errors" field for details.',
        ],
    ],

    'mail' => [
        'app_full_name' => "The Acme Platform",
        'salutation' => 'Cheers',
        'team' => 'The :app Team',
        'auto_generated' => 'This email was generated automatically - please do not reply to it. If you need help, contact our support team.',
    ],

    'auth' => [
        'titles' => [
            'already_authenticated' => 'Already Authenticated',
            'invalid_credentials' => 'Invalid Credentials',
            'email_not_verified' => 'Email Not Verified',
            'account_deactivated' => 'Account Deactivated',
            'account_locked' => 'Account Locked',
            'session_unavailable' => 'Session Unavailable',
            'invalid_magic_link' => 'Invalid Magic Link',
            'invalid_password_reset' => 'Invalid Password Reset Link',
            'password_reset_required' => 'Password Change Required',
            'invalid_two_factor_code' => 'Invalid Two-Factor Code',
            'two_factor_challenge_expired' => 'Two-Factor Challenge Expired',
            'two_factor_enrollment_required' => 'Two-Factor Setup Required',
            'captcha_failed' => 'Verification Required',
        ],

        'already_authenticated' => 'You are already logged in.',
        'invalid_credentials' => 'The provided credentials do not match our records.',
        'too_many_attempts' => 'Too many failed login attempts. Please try again in :retryAfter.',
        'email_not_verified' => 'Your email address has not been verified.',
        'account_deactivated' => 'Your account has been deactivated. Please contact support.',
        'account_locked' => 'Your account is temporarily locked until :until.',
        'two_factor_required' => 'Two-factor authentication is required to continue.',
        'session_unavailable' => 'This request could not be authenticated as a browser session. Please sign in through the application.',
        'invalid_magic_link' => 'This sign-in link is invalid or has expired. Please request a new one.',
        'invalid_password_reset' => 'This password reset link is invalid or has expired. Please request a new one.',
        'password_reset_required' => 'You must change your password before continuing.',
        'invalid_two_factor_code' => 'The code did not verify. Check your authenticator app and try again.',
        'two_factor_challenge_expired' => 'The sign-in verification has expired. Please sign in again.',
        'two_factor_enrollment_required' => 'You must set up two-factor authentication before continuing.',
        'captcha_failed' => 'The verification check did not pass. Please refresh the page and try again.',

        'tokens' => [
            'session_required' => 'API tokens can only be managed from a signed-in browser session.',
            'not_found' => 'This API token does not exist.',
        ],

        'new_device' => [
            'mail' => [
                'subject' => 'New device signed in to your account',
                'heading' => 'New device signed in',
                'intro' => 'Your account was just signed in to from a device it had not been used on before.',
                'device_label' => 'Device',
                'ip_label' => 'IP address',
                'time_label' => 'Time',
                'you' => 'If this was you, no further action is needed.',
                'not_you' => 'If you do not recognize this sign-in, change your password immediately and contact support.',
                'not_you_passwordless' => 'If you do not recognize this sign-in, someone may have access to your email inbox, since this account signs in through emailed links. Secure your email account and contact support immediately.',
            ],
        ],

        'password_changed' => [
            'mail' => [
                'subject' => 'Your password was changed',
                'heading' => 'Password changed',
                'intro' => 'The password for your account was just changed.',
                'device_label' => 'Device',
                'ip_label' => 'IP address',
                'time_label' => 'Time',
                'you' => 'If this was you, no further action is needed.',
                'not_you' => 'If you did not change your password, someone may have taken control of your account. [Reset your password](:url) immediately and contact support.',
            ],
        ],

        'two_factor_enabled' => [
            'mail' => [
                'subject' => 'Two-factor authentication was enabled',
                'heading' => 'Two-factor authentication enabled',
                'intro' => 'Two-factor authentication was just enabled on your account. Signing in now asks for a code from your authenticator app.',
                'device_label' => 'Device',
                'ip_label' => 'IP address',
                'time_label' => 'Time',
                'you' => 'If this was you, no further action is needed.',
                'not_you' => 'If you did not enable it, someone may have taken control of your account. [Reset your password](:url) immediately and contact support.',
            ],
        ],

        'two_factor_disabled' => [
            'mail' => [
                'subject' => 'Two-factor authentication was disabled',
                'heading' => 'Two-factor authentication disabled',
                'intro' => 'Two-factor authentication was just disabled on your account. Signing in no longer asks for a code.',
                'intro_admin' => 'Two-factor authentication on your account was reset by an administrator. Signing in no longer asks for a code, and you can set it up again from the security settings.',
                'device_label' => 'Device',
                'ip_label' => 'IP address',
                'time_label' => 'Time',
                'you' => 'If you expected this change, no further action is needed.',
                'not_you' => 'If you did not expect it, someone may have taken control of your account. [Reset your password](:url) immediately and contact support.',
            ],
        ],

        'lockout' => [
            'mail' => [
                'subject' => 'Your account was temporarily locked',
                'heading' => 'Account temporarily locked',
                'intro' => 'Your account was temporarily locked after several failed sign-in attempts. It unlocks automatically - no action is needed.',
                'unlock_label' => 'Sign-in available again',
                'device_label' => 'Last attempt device',
                'ip_label' => 'IP address',
                'you' => 'If this was you, wait for the lock to expire and try again, or use an emailed sign-in link.',
                'not_you' => 'If this was not you, someone tried to guess your password and did not succeed - your account remains protected, and you can still sign in as usual. If your password is weak or you use it on other sites, this is a good moment to change it.',
                'passwordless' => 'This account signs in with emailed links and has no password, so these attempts could never succeed - your account remains protected. Keep signing in with your emailed link as usual.',
            ],
        ],

        'magic_link' => [
            'sent' => 'Your request has been processed. Please check your inbox for the sign-in link.',

            'mail' => [
                'subject' => 'Your sign-in link',
                'heading' => 'Your sign-in link',
                'intro' => 'Click the button below to sign in to your account. No password is needed.',
                'welcome_subject' => 'Welcome - your sign-in link',
                'welcome_heading' => 'Welcome!',
                'welcome_intro' => 'Click the button below to sign in. Your account is created the moment you do - no password is needed.',
                'action' => 'Sign in',
                'requested_from' => 'The link was requested from:',
                'device_label' => 'Device',
                'ip_label' => 'IP address',
                'time_label' => 'Time',
                'expiry' => 'This link expires in :minutes minutes and can only be used once.',
                'ignore' => 'If you did not request this link, you can safely ignore this email.',
                'trouble' => 'If you\'re having trouble clicking the ":action" button, copy and paste the URL below into your web browser:',
            ],
        ],

        'invitation' => [
            'mail' => [
                'subject' => 'You have been invited to :app',
                'heading' => 'Welcome to :app',
                'intro' => 'An account has been created for you. Click the button below to sign in - no password is needed.',
                'intro_password' => 'An account has been created for you. Click the button below to sign in; you will choose your password when you do.',
                'action' => 'Accept invitation',
                'expiry' => 'This invitation expires in :days days and can only be used once.',
                'ignore' => 'If you were not expecting this invitation, you can safely ignore this email.',
                'trouble' => 'If you\'re having trouble clicking the ":action" button, copy and paste the URL below into your web browser:',
            ],
        ],

        'password_reset' => [
            'sent' => 'Your request has been processed. Please check your inbox for the password reset link.',
            'success' => 'Your password has been changed. You can now sign in with it.',

            'mail' => [
                'subject' => 'Reset your password',
                'heading' => 'Reset your password',
                'intro' => 'Click the button below to choose a new password for your account.',
                'action' => 'Reset password',
                'requested_from' => 'The reset was requested from:',
                'device_label' => 'Device',
                'ip_label' => 'IP address',
                'time_label' => 'Time',
                'expiry' => 'This link expires in :minutes minutes and can only be used once.',
                'ignore' => 'If you did not request a password reset, you can safely ignore this email, your password remains unchanged.',
                'trouble' => 'If you\'re having trouble clicking the ":action" button, copy and paste the URL below into your web browser:',
            ],
        ],
    ],

    'settings' => [
        'profile' => [
            'updated' => 'Your profile has been updated.',
        ],

        'preferences' => [
            'updated' => 'Your preferences have been saved.',
        ],

        'app' => [
            'updated' => 'The setting has been updated.',
        ],

        'account' => [
            'deleted' => 'Your account has been deleted.',
        ],

        'password' => [
            'updated' => 'Your password has been updated.',
        ],

        'sessions' => [
            'others_revoked' => 'All other sessions have been signed out.',
            'not_found' => 'This session does not exist.',
            'current_session' => 'The current session cannot be signed out from here. Use logout instead.',
        ],

        'identities' => [
            'not_linked' => 'This identity provider is not connected.',
        ],

        'two_factor' => [
            'titles' => [
                'already_enabled' => 'Two-Factor Already Enabled',
                'invalid_code' => 'Invalid Two-Factor Code',
                'not_enabled' => 'Two-Factor Not Enabled',
            ],

            'already_enabled' => 'Two-factor authentication is already enabled. Disable it first to set it up again.',
            'invalid_code' => 'The code did not verify. Check your authenticator app and try again.',
            'not_enabled' => 'Two-factor authentication is not enabled on this account.',
            'enabled' => 'Two-factor authentication has been enabled.',
            'disabled' => 'Two-factor authentication has been disabled.',
            'codes_regenerated' => 'New recovery codes have been generated.',
        ],
    ],

    'access' => [
        'self_revocation' => 'This change would remove an access administration permission you rely on.',
        'last_manager' => 'This change would leave a protected access permission without any active holder.',
        'protected_role' => 'The super admin role cannot be modified or deleted.',
        'reserved_role_name' => 'The super admin role name is reserved.',
        'super_admin_assignment' => 'Super admin membership cannot be changed through the API.',
        'unknown_protectable' => 'This resource cannot carry required-permission rules.',
        'user_created' => 'The account has been created.',
        'user_invited' => 'The account has been created and the invitation has been emailed.',
        'invitation_sent' => 'The invitation has been emailed.',
        'invitation_not_pending' => 'An invitation can only be sent to an account that has never signed in.',
        'role_created' => 'The role has been created.',
        'role_updated' => 'The role has been updated.',
        'role_deleted' => 'The role has been deleted.',
        'roles_updated' => 'The user\'s roles have been updated.',
        'permissions_updated' => 'The permissions have been updated.',
        'rules_updated' => 'The required-permission rules have been updated.',
        'account_updated' => 'The account has been updated.',
        'password_reset_forced' => 'A temporary password has been set; the user must change it at the next sign-in.',
        'two_factor_reset' => 'Two-factor authentication has been reset for this account.',
        'user_deleted' => 'The account has been deleted.',
        'self_delete' => 'You cannot delete your own account from the access administration.',
        'impersonation_started' => 'You are now signed in as this user.',
        'impersonation_ended' => 'Impersonation has ended.',
        'impersonation_self' => 'You cannot impersonate your own account.',
        'impersonation_nested' => 'Impersonation is already active; end it before starting another.',
        'impersonation_target_ineligible' => 'This account cannot be signed in to.',
        'impersonation_above_tier' => 'Impersonating an access administrator requires the super admin tier.',
        'target_above_tier' => 'Managing this account requires privileges it holds and you do not.',
        'role_holder_above_tier' => 'This role is held by an account outside your reach, so the privileged permissions it grants cannot be removed.',
        'grant_above_ceiling' => 'You can only grant permissions you hold yourself.',
        'impersonation_not_active' => 'No impersonation is active on this session.',
        'impersonation_blocked' => 'This action is not available while impersonating a user.',
    ],
];
