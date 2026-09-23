# Framework Role Separation (Code-Aligned)

This project enforces **strict role separation** for staff operations:

- `admin`
  - Accesses `/api/admin/*` endpoints only.
  - Handles CMS, reports, user management, room management, audit trail, and admin dashboards.
- `receptionist`
  - Accesses `/api/receptionist/*` endpoints only.
  - Handles operational tasks: reservations, check-in/check-out, walk-ins, front-desk payments, cancellations, and rebookings.
- `guest`
  - Uses public/client endpoints (`/api/client/*`) and token-based booking flows.
  - Not a staff-authenticated role in `users`.

## Security Policy

- Least privilege is applied between staff roles.
- Admin users do **not** inherit receptionist API permissions.
- Receptionist operations are protected by `role:receptionist` middleware.

## Thesis Alignment Note

For framework diagrams and defense slides, present Guest/Receptionist/Admin as **separate responsibility lanes** with no admin override into receptionist operational endpoints.
