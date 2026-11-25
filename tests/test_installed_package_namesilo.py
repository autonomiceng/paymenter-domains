"""NameSilo frozen registry inputs and renewal readback from installed ZIP bytes."""
# MIT, Copyright (c) 2026 Autonomic Engineering.
from test_installed_package import main

if __name__ == '__main__':
    # Copy the runner's app while it is unused; the renewal lifecycle then holds it.
    main('namesilo-registration', private_copy=True)
    main('namesilo-renewal')
