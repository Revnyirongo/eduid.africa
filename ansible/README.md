# Ansible Deployment (Single Modern Stack)

This playbook installs Docker, clones the repo, writes `.env`, generates a self-signed TLS cert (optional), and starts the stack.

## Quick Start
1. Edit `ansible/inventory.ini` and set your VM public IP.
2. Edit `ansible/group_vars/all.yml` with your domain and secrets.
3. Run:
   ```bash
   ansible-playbook -i ansible/inventory.ini ansible/playbook.yml
   ```

## Notes
- For public deployment, set real DNS:
  - `A` record for `idp.<base_domain>` to the VM IP
  - wildcard `*.${base_domain}` if you plan multiple tenants
- Use real TLS certificates before exposing to the internet.
- If you want the legacy stack instead of modern, set:
  - `compose_file: docker-compose.yml`

