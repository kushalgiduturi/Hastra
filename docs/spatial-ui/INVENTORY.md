# Spatial UI inventory

Generated in Phase 0 from a scan of every PHP entry point under auth/, portals/, labs/, legal/ plus 404.php, dashboard.php and index.php, then checked by hand against HASTRA_ARCHITECTURE_AND_EXECUTION_GUIDE.md.

Columns: **CSS** is the stylesheets the file links directly (every themed page also gets hastra-atmosphere.css and hastra-ux.css from core/theme.js, and portals, labs and dashboard get theme-netflix.css + netflix-bento.css the same way). **Inline** is the number of `<style>` blocks and their total line count. **Partials** is the layout or nav file it includes. **Components** is detected from markup and class names, so treat it as a starting checklist, not a spec.

## Totals

- Rendered pages: 63. Layout and nav partials: 7. Endpoints with no markup of their own (redirects, JSON): 6.
- Pages with an inline `<style>` block: 50, about 8359 lines in total. The largest are auth/login.php, auth/register.php, auth/reset.php, auth/verify_register.php, portals/user/newuser_portal.php, portals/user/profile.php, portals/client/my_requirements.php and portals/admin/project_portal.php.
- Pages linking theme-fog-boxless.css directly: 51 (plus labs/_boot.php and legal/_layout.php, which cover 5 labs pages and 5 legal pages).

## 4b auth

| File | Kind | CSS (direct) | Inline | Partials | Components |
|---|---|---|---|---|---|
| `auth/forgot.php` | page | fonts, theme-authkit, theme-contrast, theme-fog-boxless, theme | 1 / 407l | - | form, pipeline |
| `auth/forgot_otp.php` | page | fonts, theme-authkit, theme-contrast, theme-fog-boxless, theme | 1 / 417l | - | form, OTP boxes |
| `auth/google_auth.php` | endpoint | - | - | countries.php | - |
| `auth/login.php` | page | fonts, theme-authkit, theme-contrast, theme-fog-boxless, theme | 2 / 752l | - | tabs, badges, form, pipeline, charts, reCAPTCHA |
| `auth/logout.php` | endpoint | - | - | - | - |
| `auth/otp.php` | page | fonts, theme-authkit, theme-contrast, theme-fog-boxless, theme | 1 / 386l | - | form, OTP boxes |
| `auth/register.php` | page | fonts, hastra-select, intlTelInput, theme-authkit, theme-contrast, theme-fog-boxless, theme | 1 / 637l | countries.php | tabs, stat cards, badges, form, custom select, dropzone, pipeline |
| `auth/reset.php` | page | fonts, theme-authkit, theme-contrast, theme-fog-boxless, theme | 1 / 490l | - | form |
| `auth/set_password.php` | page | fonts, theme-authkit, theme-contrast, theme-fog-boxless, theme | 1 / 457l | - | form |
| `auth/verify_register.php` | page | fonts, theme-authkit, theme-contrast, theme-fog-boxless, theme | 1 / 495l | - | form, OTP boxes |

## 4c sysadmin

| File | Kind | CSS (direct) | Inline | Partials | Components |
|---|---|---|---|---|---|
| `portals/sysadmin/_nav.php` | partial | - | - | - | badges |
| `portals/sysadmin/delete_user.php` | endpoint | - | - | - | - |
| `portals/sysadmin/insights.php` | page | fonts, theme-contrast, theme-fog-boxless, theme | 1 / 71l | _nav.php | table, form, accordion, charts, code/pre |
| `portals/sysadmin/logs.php` | page | custom-dropdowns, fonts, gooey-search, theme-contrast, theme-fog-boxless, theme | 1 / 57l | _nav.php | table, sections, badges, custom select, search |
| `portals/sysadmin/migrate.php` | page | fonts, theme-contrast, theme-fog-boxless, theme | 1 / 31l | _nav.php | form, code/pre |
| `portals/sysadmin/roles.php` | page | custom-dropdowns, fonts, gooey-search, theme-contrast, theme-fog-boxless, theme | 1 / 138l | _nav.php | table, modal, stat cards, sections, badges, form, custom select, OTP boxes, search |
| `portals/sysadmin/security_dashboard.php` | page | fonts, theme-contrast, theme-fog-boxless, theme | 1 / 34l | _nav.php | sections |
| `portals/sysadmin/sysadmin_portal.php` | page | fonts, theme-contrast, theme-fog-boxless, theme | 1 / 19l | _nav.php | sections |

## 4d admin

| File | Kind | CSS (direct) | Inline | Partials | Components |
|---|---|---|---|---|---|
| `portals/admin/_nav.php` | partial | - | - | - | stat cards, badges |
| `portals/admin/admin_portal.php` | page | fonts, theme-contrast, theme-fog-boxless, theme, tour | 1 / 49l | _nav.php, verify_audit.php | sections, form, tour, code/pre |
| `portals/admin/attendance.php` | page | calendar-authkit, fonts, theme-authkit, theme-contrast, theme-fog-boxless, theme | 1 / 123l | _nav.php | table, modal, stat cards, sections, form, dropzone, calendar, code/pre |
| `portals/admin/billing.php` | page | custom-dropdowns, fonts, theme-contrast, theme-fog-boxless, theme | 1 / 69l | _nav.php | table, sections, badges, form, custom select |
| `portals/admin/create_employee.php` | page | custom-dropdowns, fonts, theme-contrast, theme-fog-boxless, theme | 1 / 50l | _nav.php | sections, form, custom select |
| `portals/admin/delivery.php` | page | custom-dropdowns, fonts, theme-contrast, theme-fog-boxless, theme | 1 / 64l | _nav.php | table, sections, badges, form, custom select |
| `portals/admin/deployments.php` | page | fonts, theme-contrast, theme-fog-boxless, theme | 1 / 42l | _nav.php | sections, badges, form |
| `portals/admin/directory.php` | page | fonts, theme-contrast, theme-fog-boxless, theme | 1 / 39l | _nav.php | table, sections, badges, form |
| `portals/admin/doc_editor.php` | page | client_pages, doc_content, fonts, theme-contrast, theme-fog-boxless, theme | 1 / 25l | - | sections, badges, form, code/pre |
| `portals/admin/leave_management.php` | page | custom-dropdowns, fonts, theme-contrast, theme-fog-boxless, theme | 1 / 82l | _nav.php | table, stat cards, sections, badges, form, custom select |
| `portals/admin/project_portal.php` | page | custom-dropdowns, fonts, theme-contrast, theme-fog-boxless, theme | 1 / 286l | _nav.php | sections, badges, form, custom select |
| `portals/admin/requirements.php` | page | custom-dropdowns, fonts, gooey-search, theme-contrast, theme-fog-boxless, theme | 1 / 124l | _nav.php | table, stat cards, sections, badges, form, custom select, search |
| `portals/admin/security.php` | page | fonts, theme-contrast, theme-fog-boxless, theme | 1 / 39l | _nav.php | table, sections, badges |
| `portals/admin/testing.php` | page | fonts, theme-contrast, theme-fog-boxless, theme | 1 / 65l | _nav.php | table, stat cards, sections, badges |

## 4e employee

| File | Kind | CSS (direct) | Inline | Partials | Components |
|---|---|---|---|---|---|
| `portals/emlpoyee/_nav.php` | partial | - | - | - | badges |
| `portals/emlpoyee/deployment_portal.php` | page | fonts, theme-contrast, theme-fog-boxless, theme, tour | 1 / 160l | _nav.php | stat cards, sections, badges, form, tour |
| `portals/emlpoyee/employee_portal.php` | page | calendar-authkit, custom-dropdowns, fonts, theme-authkit, theme-contrast, theme-fog-boxless, theme, tour | 1 / 72l | _nav.php | modal, sections, badges, form, custom select, calendar, tour |
| `portals/emlpoyee/my_tasks.php` | page | custom-dropdowns, fonts, theme-contrast, theme-fog-boxless, theme, tour | 2 / 174l | _nav.php | table, modal, stat cards, sections, badges, form, custom select, dropzone, tour |
| `portals/emlpoyee/team_lead.php` | page | custom-dropdowns, fonts, theme-contrast, theme-fog-boxless, theme | 1 / 258l | _nav.php | modal, sections, badges, form, custom select, dropzone |
| `portals/emlpoyee/testing_portal.php` | page | custom-dropdowns, fonts, theme-contrast, theme-fog-boxless, theme, tour | 1 / 220l | _nav.php | stat cards, sections, badges, form, custom select, dropzone, tour |

## 4f client

| File | Kind | CSS (direct) | Inline | Partials | Components |
|---|---|---|---|---|---|
| `portals/client/_nav.php` | partial | - | - | - | badges |
| `portals/client/client_portal.php` | page | client_pages, fonts, theme-contrast, theme-fog-boxless, theme, tour | 1 / 20l | _nav.php | sections, tour |
| `portals/client/delivery_billing.php` | page | client_pages, custom-dropdowns, fonts, theme-contrast, theme-fog-boxless, theme | 1 / 101l | _nav.php | modal, sections, badges, form, custom select |
| `portals/client/doc_view.php` | page | client_pages, doc_content, fonts, theme-contrast, theme-fog-boxless, theme | 1 / 9l | _nav.php | sections |
| `portals/client/docs.php` | page | client_pages, fonts, theme-contrast, theme-fog-boxless, theme | - | _nav.php | sections |
| `portals/client/my_projects.php` | page | client_pages, fonts, theme-contrast, theme-fog-boxless, theme | 1 / 189l | _nav.php | sections, badges, form |
| `portals/client/my_requirements.php` | page | client_pages, fonts, theme-contrast, theme-fog-boxless, theme | 1 / 323l | _nav.php | table, modal, sections, badges, form, pipeline, accordion |
| `portals/client/project_view.php` | page | client_pages, fonts, theme-authkit, theme-contrast, theme-fog-boxless, theme | 1 / 45l | _nav.php | terminal |
| `portals/client/requirements_diff.php` | page | fonts, theme-contrast, theme-fog-boxless, theme | 1 / 30l | project_portal.php | stat cards, sections, badges, form |
| `portals/client/security_view.php` | page | client_pages, fonts, theme-contrast, theme-fog-boxless, theme | - | _nav.php | table, stat cards, sections |
| `portals/client/submit_requirement.php` | page | client_pages, custom-dropdowns, fonts, theme-contrast, theme-fog-boxless, theme | 1 / 110l | _nav.php | sections, form, custom select |
| `portals/client/team.php` | page | client_pages, custom-dropdowns, fonts, theme-contrast, theme-fog-boxless, theme | 1 / 63l | _nav.php | table, modal, stat cards, sections, badges, form, custom select, dropzone, code/pre |

## 4g shared flows

| File | Kind | CSS (direct) | Inline | Partials | Components |
|---|---|---|---|---|---|
| `dashboard.php` | page | fonts, theme-contrast, theme-fog-boxless, theme | 1 / 257l | - |  |
| `portals/deliveries/dossier.php` | page | fonts, theme-contrast, theme-fog-boxless, theme | 1 / 35l | - | table, sections, badges, form, terminal |
| `portals/deliveries/terminal.php` | page | fonts, handover-terminal, theme-authkit, theme-contrast, theme-fog-boxless, theme | - | - | form, terminal, code/pre |
| `portals/index.php` | page | fonts, theme-contrast, theme-fog-boxless, theme | 1 / 32l | - |  |
| `portals/projects/signoff.php` | page | fonts, theme-contrast, theme-fog-boxless, theme | 1 / 30l | - | sections, badges, form, OTP boxes |
| `portals/security/scan_center.php` | page | fonts, scan-center, theme-authkit, theme-contrast, theme-fog-boxless, theme | - | - | table, modal, stat cards, dropzone, toast, search, code/pre |
| `portals/user/newuser_portal.php` | page | fonts, theme-contrast, theme-fog-boxless, theme | 1 / 361l | - | badges, pipeline |
| `portals/user/profile.php` | page | fonts, intlTelInput, theme-contrast, theme-fog-boxless, theme | 1 / 355l | - | table, tabs, sections, badges, form, pipeline, OTP boxes |

## 4h labs

| File | Kind | CSS (direct) | Inline | Partials | Components |
|---|---|---|---|---|---|
| `labs/_boot.php` | partial | theme-contrast, theme-fog-boxless, theme, tools-cyber | - | - | tabs, pipeline, toast |
| `labs/api/progress.php` | endpoint | - | - | _boot.php | - |
| `labs/api/vote.php` | endpoint | - | - | _boot.php | - |
| `labs/api/youtube.php` | endpoint | - | - | _boot.php | - |
| `labs/auth.php` | page (via layout) | - | - | _boot.php | form, code/pre |
| `labs/crypto.php` | page (via layout) | - | - | _boot.php | table, tabs, stat cards, badges, dropzone, code/pre |
| `labs/index.php` | page (via layout) | - | - | _boot.php | pipeline, accordion |
| `labs/siem.php` | page (via layout) | - | - | _boot.php | table, tabs, badges, form, pipeline, charts, code/pre |
| `labs/syllabus.php` | page (via layout) | - | - | _boot.php | dropzone |

## 4i public

| File | Kind | CSS (direct) | Inline | Partials | Components |
|---|---|---|---|---|---|
| `404.php` | page | - | 1 / 37l | - |  |
| `index.php` | page | - | 1 / 14l | - |  |
| `legal/_cookie_banner.php` | partial | - | - | - |  |
| `legal/_layout.php` | partial | theme-authkit, theme-contrast, theme-fog-boxless, theme | - | - |  |
| `legal/cookies.php` | page (via layout) | - | - | _layout.php | table, code/pre, reCAPTCHA |
| `legal/delete-data.php` | page (via layout) | - | 1 / 16l | _layout.php, data_deletion.php | form |
| `legal/privacy.php` | page (via layout) | - | - | _layout.php | table, stat cards, reCAPTCHA |
| `legal/refunds.php` | page (via layout) | - | - | _layout.php | table |
| `legal/terms.php` | page (via layout) | - | - | _layout.php |  |
