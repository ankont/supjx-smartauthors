# SuperSoftJx SmartAuthors (`plg_system_smartauthors`)

**SuperSoftJx SmartAuthors** is a Joomla system plugin providing contextual, additive authoring permissions per category subtree for Joomla 5.4.x and Joomla 6.x.

## Key Principles

- **Additive Model**: Effective permission = Joomla ACL permission OR SmartAuthors contextual permission. SmartAuthors never revokes permissions already granted by Joomla ACL.
- **Hierarchical Policy Resolution**: Subtree categories inherit policy from the nearest ancestor with a Custom policy (Managed Root), unless explicitly overridden by their own Custom policy.
- **Personal & Collaborative Policies**:
  - **Personal**: Owned by 0..1 Joomla user (Owner). Supports initial alias=username discovery for unbound categories and resilient category alias synchronization on username changes.
  - **Collaborative**: Contributed to by 0..N Joomla users (Contributors) sharing uniform editorial permissions.
- **Strict Subtree Boundary**: Editorial and category management actions are strictly bounded to the effective managed subtree.

## Target Environments

- Joomla 5.4.x (specifically 5.4.8+)
- Joomla 6.x
- PHP 8.1+

## Build

To create the installation ZIP package:

```cmd
build.bat
```

The installable archive is created in `build/output/`.
