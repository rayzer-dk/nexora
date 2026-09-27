# Database policy

The first production schema will use:
- InnoDB;
- utf8mb4;
- installer-selected modern collation;
- UUIDv7 identifiers encoded efficiently in storage;
- explicit foreign keys where lifecycle ownership is clear;
- append-only audit/event records for security-sensitive actions;
- no EAV for core product data.

Product extensibility will use typed attributes and bounded JSON extension metadata, not an EAV replacement for the relational model.
