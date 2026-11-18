# Twig controller/template audit

Workflow: .github/workflows/twig-template-qa.yml

GitHub Actions installs the required application dependencies; there is no production dependency and nothing to install on a local computer.

The built-in Twig parser (CodeCart) and Symfony lint:twig (Nexora) inspect template syntax. A separate Python audit matches statically obvious controller render calls to Twig root variables, identifies potential unused locals, and checks nested keys for known PHP literal arrays.

Mapping covers $this->load->view('route', $data) for CodeCart admin/catalog and $this->render('@storefront/route.html.twig', [...]) for Nexora. The inventory is advisory because dynamic OCMOD/Events, Symfony globals, template inheritance/includes, hooks and runtime data cannot be proven absent by this analysis. Findings must be reviewed before edits; never automatically delete reported code.

Run the blocking regression fixture with: python3 tools/quality/twig_context_audit.py --self-test
Run the inventory with --mode codecart or --mode nexora. Reports are stored as GitHub Actions artifacts.