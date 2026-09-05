<?php

declare(strict_types=1);

return [
    'errors' => [
        'titles' => [
            'not_found' => 'Resursă negăsită',
            'unauthorized' => 'Neautorizat',
            'forbidden' => 'Interzis',
            'too_many_requests' => 'Prea multe cereri',
            'unsupported_media_type' => 'Tip de conținut neacceptat',
            'method_not_allowed' => 'Metodă nepermisă',
            'page_expired' => 'Pagina a expirat',
            'internal_server_error' => 'Eroare internă de server',
            'validation_failed' => 'Validare eșuată',
        ],

        'http' => [
            'not_found' => 'Resursa solicitată nu a putut fi găsită sau a fost mutată.',
            'unauthorized' => 'Este necesară autentificarea pentru a accesa această resursă.',
            'forbidden_access' => 'Nu ai permisiunile necesare pentru această acțiune.',
            'too_many_requests' => 'Ai depășit numărul permis de cereri. Te rugăm să încerci din nou în :seconds secunde.',
            'unsupported_media_type' => 'Tip de conținut neacceptat. Folosește corpuri de cerere application/json.',

            'method_not_allowed' => 'Metoda :method nu este acceptată pentru această rută.',
            'page_expired' => 'Sesiunea ta a expirat. Reîncarcă pagina și încearcă din nou.',
            'internal_server_error' => 'A apărut o eroare neașteptată. Te rugăm să încerci din nou mai târziu.',
        ],

        'validation' => [
            'failed' => 'Datele introduse nu sunt valide. Verifică câmpul "errors" pentru detalii.',
        ],
    ],

    'mail' => [
        'app_full_name' => "Platforma Acme",
        'salutation' => 'Numai bine',
        'team' => 'Echipa :app',
        'auto_generated' => 'Acest email a fost generat automat - te rugăm să nu răspunzi la el. Dacă ai nevoie de ajutor, contactează echipa de suport.',
    ],

    'auth' => [
        'titles' => [
            'already_authenticated' => 'Deja autentificat',
            'invalid_credentials' => 'Date de autentificare invalide',
            'email_not_verified' => 'Email neverificat',
            'account_deactivated' => 'Cont dezactivat',
            'account_locked' => 'Cont blocat',
            'session_unavailable' => 'Sesiune indisponibilă',
            'invalid_magic_link' => 'Link de autentificare invalid',
            'invalid_password_reset' => 'Link de resetare a parolei invalid',
            'password_reset_required' => 'Schimbarea parolei este necesară',
            'invalid_two_factor_code' => 'Cod de verificare invalid',
            'two_factor_challenge_expired' => 'Verificarea autentificării a expirat',
            'two_factor_enrollment_required' => 'Configurarea autentificării în doi pași este necesară',
            'captcha_failed' => 'Verificare necesară',
        ],

        'already_authenticated' => 'Ești deja autentificat.',
        'invalid_credentials' => 'Datele introduse nu corespund înregistrărilor noastre.',
        'too_many_attempts' => 'Prea multe încercări eșuate. Încearcă din nou în :retryAfter.',
        'email_not_verified' => 'Adresa de email nu a fost verificată.',
        'account_deactivated' => 'Contul tău a fost dezactivat. Contactează suportul.',
        'account_locked' => 'Contul tău este blocat temporar până la :until.',
        'two_factor_required' => 'Autentificarea în doi pași este necesară pentru a continua.',
        'session_unavailable' => 'Această cerere nu a putut fi autentificată ca sesiune de browser. Te rugăm să te autentifici prin aplicație.',
        'invalid_magic_link' => 'Acest link de autentificare este invalid sau a expirat. Te rugăm să soliciți unul nou.',
        'invalid_password_reset' => 'Acest link de resetare a parolei este invalid sau a expirat. Te rugăm să soliciți unul nou.',
        'password_reset_required' => 'Trebuie să îți schimbi parola înainte de a continua.',
        'invalid_two_factor_code' => 'Codul nu a putut fi verificat. Verifică aplicația de autentificare și încearcă din nou.',
        'two_factor_challenge_expired' => 'Verificarea autentificării a expirat. Te rugăm să te autentifici din nou.',
        'two_factor_enrollment_required' => 'Trebuie să configurezi autentificarea în doi pași înainte de a continua.',
        'captcha_failed' => 'Verificarea nu a trecut. Te rugăm să reîncarci pagina și să încerci din nou.',

        'tokens' => [
            'session_required' => 'Tokenurile API pot fi gestionate doar dintr-o sesiune de browser autentificată.',
            'not_found' => 'Acest token API nu există.',
        ],

        'new_device' => [
            'mail' => [
                'subject' => 'Un dispozitiv nou s-a conectat la contul tău',
                'heading' => 'Dispozitiv nou conectat',
                'intro' => 'Contul tău tocmai a fost accesat de pe un dispozitiv de pe care nu a mai fost folosit.',
                'device_label' => 'Dispozitiv',
                'ip_label' => 'Adresă IP',
                'time_label' => 'Ora',
                'you' => 'Dacă ai fost tu, nu este nevoie de nicio acțiune.',
                'not_you' => 'Dacă nu recunoști această conectare, schimbă-ți imediat parola și contactează suportul.',
                'not_you_passwordless' => 'Dacă nu recunoști această conectare, este posibil ca cineva să aibă acces la căsuța ta de email, deoarece acest cont se autentifică prin linkuri trimise pe email. Securizează-ți contul de email și contactează imediat suportul.',
            ],
        ],

        'password_changed' => [
            'mail' => [
                'subject' => 'Parola ta a fost schimbată',
                'heading' => 'Parolă schimbată',
                'intro' => 'Parola contului tău tocmai a fost schimbată.',
                'device_label' => 'Dispozitiv',
                'ip_label' => 'Adresă IP',
                'time_label' => 'Ora',
                'you' => 'Dacă ai fost tu, nu este nevoie de nicio acțiune.',
                'not_you' => 'Dacă nu tu ai schimbat parola, este posibil ca cineva să fi preluat controlul contului tău. [Resetează-ți parola](:url) imediat și contactează suportul.',
            ],
        ],

        'two_factor_enabled' => [
            'mail' => [
                'subject' => 'Autentificarea în doi pași a fost activată',
                'heading' => 'Autentificare în doi pași activată',
                'intro' => 'Autentificarea în doi pași tocmai a fost activată pe contul tău. Autentificarea cere acum un cod din aplicația de autentificare.',
                'device_label' => 'Dispozitiv',
                'ip_label' => 'Adresă IP',
                'time_label' => 'Ora',
                'you' => 'Dacă ai fost tu, nu este nevoie de nicio acțiune.',
                'not_you' => 'Dacă nu tu ai activat-o, este posibil ca cineva să fi preluat controlul contului tău. [Resetează-ți parola](:url) imediat și contactează suportul.',
            ],
        ],

        'two_factor_disabled' => [
            'mail' => [
                'subject' => 'Autentificarea în doi pași a fost dezactivată',
                'heading' => 'Autentificare în doi pași dezactivată',
                'intro' => 'Autentificarea în doi pași tocmai a fost dezactivată pe contul tău. Autentificarea nu mai cere un cod.',
                'intro_admin' => 'Autentificarea în doi pași a contului tău a fost resetată de un administrator. Autentificarea nu mai cere un cod, iar tu o poți configura din nou din setările de securitate.',
                'device_label' => 'Dispozitiv',
                'ip_label' => 'Adresă IP',
                'time_label' => 'Ora',
                'you' => 'Dacă te așteptai la această schimbare, nu este nevoie de nicio acțiune.',
                'not_you' => 'Dacă nu te așteptai la ea, este posibil ca cineva să fi preluat controlul contului tău. [Resetează-ți parola](:url) imediat și contactează suportul.',
            ],
        ],

        'lockout' => [
            'mail' => [
                'subject' => 'Contul tău a fost blocat temporar',
                'heading' => 'Cont blocat temporar',
                'intro' => 'Contul tău a fost blocat temporar după mai multe încercări eșuate de autentificare. Se deblochează automat - nu este nevoie de nicio acțiune.',
                'unlock_label' => 'Autentificare disponibilă din nou',
                'device_label' => 'Dispozitivul ultimei încercări',
                'ip_label' => 'Adresă IP',
                'you' => 'Dacă ai fost tu, așteaptă expirarea blocării și încearcă din nou, sau folosește un link de autentificare trimis pe email.',
                'not_you' => 'Dacă nu ai fost tu, cineva a încercat să îți ghicească parola și nu a reușit - contul tău rămâne protejat, iar tu te poți autentifica normal. Dacă parola ta este slabă sau o folosești și pe alte site-uri, acesta este un moment bun să o schimbi.',
                'passwordless' => 'Acest cont se autentifică prin linkuri trimise pe email și nu are parolă, așa că aceste încercări nu ar fi putut reuși - contul tău rămâne protejat. Continuă să te autentifici cu linkul de pe email ca de obicei.',
            ],
        ],

        'magic_link' => [
            'sent' => 'Cererea ta a fost procesată. Te rugăm să îți verifici căsuța de email pentru linkul de autentificare.',

            'mail' => [
                'subject' => 'Linkul tău de autentificare',
                'heading' => 'Linkul tău de autentificare',
                'intro' => 'Apasă butonul de mai jos pentru a te autentifica în contul tău. Nu este nevoie de parolă.',
                'welcome_subject' => 'Bun venit - linkul tău de autentificare',
                'welcome_heading' => 'Bun venit!',
                'welcome_intro' => 'Apasă butonul de mai jos pentru a te autentifica. Contul tău este creat în acel moment - nu este nevoie de parolă.',
                'action' => 'Autentifică-te',
                'requested_from' => 'Linkul a fost solicitat de pe:',
                'device_label' => 'Dispozitiv',
                'ip_label' => 'Adresă IP',
                'time_label' => 'Ora',
                'expiry' => 'Acest link expiră în :minutes minute și poate fi folosit o singură dată.',
                'ignore' => 'Dacă nu ai solicitat acest link, poți ignora acest email.',
                'trouble' => 'Dacă nu poți apăsa butonul ":action", copiază și lipește adresa de mai jos în browserul tău:',
            ],
        ],

        'invitation' => [
            'mail' => [
                'subject' => 'Ai fost invitat în :app',
                'heading' => 'Bun venit în :app',
                'intro' => 'A fost creat un cont pentru tine. Apasă butonul de mai jos pentru a te autentifica - nu este nevoie de parolă.',
                'intro_password' => 'A fost creat un cont pentru tine. Apasă butonul de mai jos pentru a te autentifica; îți vei alege parola atunci.',
                'action' => 'Acceptă invitația',
                'expiry' => 'Această invitație expiră în :days zile și poate fi folosită o singură dată.',
                'ignore' => 'Dacă nu așteptai această invitație, poți ignora acest email.',
                'trouble' => 'Dacă nu poți apăsa butonul ":action", copiază și lipește adresa de mai jos în browserul tău:',
            ],
        ],

        'password_reset' => [
            'sent' => 'Cererea ta a fost procesată. Te rugăm să îți verifici căsuța de email pentru linkul de resetare a parolei.',
            'success' => 'Parola ta a fost schimbată. Te poți autentifica acum cu ea.',

            'mail' => [
                'subject' => 'Resetează-ți parola',
                'heading' => 'Resetează-ți parola',
                'intro' => 'Apasă butonul de mai jos pentru a alege o parolă nouă pentru contul tău.',
                'action' => 'Resetează parola',
                'requested_from' => 'Resetarea a fost solicitată de pe:',
                'device_label' => 'Dispozitiv',
                'ip_label' => 'Adresă IP',
                'time_label' => 'Ora',
                'expiry' => 'Acest link expiră în :minutes minute și poate fi folosit o singură dată.',
                'ignore' => 'Dacă nu ai solicitat resetarea parolei, poți ignora acest email, parola ta rămâne neschimbată.',
                'trouble' => 'Dacă nu poți apăsa butonul ":action", copiază și lipește adresa de mai jos în browserul tău:',
            ],
        ],
    ],

    'settings' => [
        'profile' => [
            'updated' => 'Profilul tău a fost actualizat.',
        ],

        'preferences' => [
            'updated' => 'Preferințele tale au fost salvate.',
        ],

        'app' => [
            'updated' => 'Setarea a fost actualizată.',
        ],

        'account' => [
            'deleted' => 'Contul tău a fost șters.',
        ],

        'password' => [
            'updated' => 'Parola ta a fost actualizată.',
        ],

        'sessions' => [
            'others_revoked' => 'Toate celelalte sesiuni au fost deconectate.',
            'not_found' => 'Această sesiune nu există.',
            'current_session' => 'Sesiunea curentă nu poate fi deconectată de aici. Folosește deconectarea.',
        ],

        'identities' => [
            'not_linked' => 'Acest furnizor de identitate nu este conectat.',
        ],

        'two_factor' => [
            'titles' => [
                'already_enabled' => 'Autentificarea în doi pași este deja activată',
                'invalid_code' => 'Cod de verificare invalid',
                'not_enabled' => 'Autentificarea în doi pași nu este activată',
            ],

            'already_enabled' => 'Autentificarea în doi pași este deja activată. Dezactiveaz-o mai întâi pentru a o configura din nou.',
            'invalid_code' => 'Codul nu a putut fi verificat. Verifică aplicația de autentificare și încearcă din nou.',
            'not_enabled' => 'Autentificarea în doi pași nu este activată pe acest cont.',
            'enabled' => 'Autentificarea în doi pași a fost activată.',
            'disabled' => 'Autentificarea în doi pași a fost dezactivată.',
            'codes_regenerated' => 'Au fost generate coduri de recuperare noi.',
        ],
    ],

    'access' => [
        'self_revocation' => 'Această modificare ți-ar elimina o permisiune de administrare a accesului pe care te bazezi.',
        'last_manager' => 'Această modificare ar lăsa o permisiune protejată de acces fără niciun deținător activ.',
        'protected_role' => 'Rolul de super admin nu poate fi modificat sau șters.',
        'reserved_role_name' => 'Numele rolului de super admin este rezervat.',
        'super_admin_assignment' => 'Apartenența la rolul de super admin nu poate fi modificată prin API.',
        'unknown_protectable' => 'Această resursă nu poate avea reguli de permisiuni obligatorii.',
        'user_created' => 'Contul a fost creat.',
        'user_invited' => 'Contul a fost creat, iar invitația a fost trimisă pe email.',
        'invitation_sent' => 'Invitația a fost trimisă pe email.',
        'invitation_not_pending' => 'Invitația poate fi trimisă doar unui cont care nu s-a autentificat niciodată.',
        'role_created' => 'Rolul a fost creat.',
        'role_updated' => 'Rolul a fost actualizat.',
        'role_deleted' => 'Rolul a fost șters.',
        'roles_updated' => 'Rolurile utilizatorului au fost actualizate.',
        'permissions_updated' => 'Permisiunile au fost actualizate.',
        'rules_updated' => 'Regulile de permisiuni obligatorii au fost actualizate.',
        'account_updated' => 'Contul a fost actualizat.',
        'password_reset_forced' => 'A fost setată o parolă temporară; utilizatorul trebuie să o schimbe la următoarea autentificare.',
        'two_factor_reset' => 'Autentificarea în doi pași a fost resetată pentru acest cont.',
        'user_deleted' => 'Contul a fost șters.',
        'self_delete' => 'Nu îți poți șterge propriul cont din administrarea accesului.',
        'impersonation_started' => 'Ești acum autentificat ca acest utilizator.',
        'impersonation_ended' => 'Impersonarea s-a încheiat.',
        'impersonation_self' => 'Nu îți poți impersona propriul cont.',
        'impersonation_nested' => 'O impersonare este deja activă; încheie-o înainte de a porni alta.',
        'impersonation_target_ineligible' => 'Autentificarea în acest cont nu este posibilă.',
        'impersonation_above_tier' => 'Impersonarea unui administrator de acces necesită nivelul de super admin.',
        'target_above_tier' => 'Administrarea acestui cont necesită privilegii pe care contul le deține, iar tu nu.',
        'grant_above_ceiling' => 'Poți acorda doar permisiuni pe care le deții tu însuți.',
        'impersonation_not_active' => 'Nicio impersonare nu este activă pe această sesiune.',
        'impersonation_blocked' => 'Această acțiune nu este disponibilă în timpul impersonării unui utilizator.',
    ],
];
