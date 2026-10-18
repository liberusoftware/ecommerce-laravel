# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Initial documentation structure with CHANGELOG, CONTRIBUTING, and SECURITY policies

### Changed
- Enhanced project documentation and specifications

### Removed
- The four Warranty and Claims packages (`liberusoftware/module-ecommerce-warranty-and-claims` and its
  `-api`, `-filament` and `-livewire` adapters) and their git submodules. Nothing in the application used
  them, and as submodules with local path repositories they broke installation from a plain clone.

  **Existing installations:** fresh installs no longer create the module's tables, but databases that
  already ran its migration keep nine empty-unless-used tables — `warranty_terms`, `warranty_providers`,
  `warranty_registrations`, `warranty_claims`, `warranty_claim_events`, `warranty_scans`,
  `warranty_labels`, `warranty_manifests` and `warranty_exceptions` — and the
  `2026_09_07_000000_create_warranty_and_claims_tables` row in `migrations`. Their foreign keys reference
  only each other, so they affect nothing else. Nothing drops them automatically; check they hold no
  data you need before removing them by hand.
