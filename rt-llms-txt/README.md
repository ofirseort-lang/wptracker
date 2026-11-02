# RT LLMs.txt

WordPress plugin that serves `/llms.txt` and `/llms-full.txt` from published content, rebuilt nightly.
Requirements and design: `docs/rt-llms-txt/SRS.md`.

## Status: skeleton v0.1.0 (not tested inside WordPress yet)

Built: serving with ETag / 304 / HEAD, cached files with a rebuild lock, meta-tag fetcher (description, robots, canonical read from the rendered page), nightly job with diff log and catch-up, immediate removal on unpublish / delete.

Not built yet (see SRS): setup wizard (5.8), settings page, domain taxonomy and featured articles (FR-12..FR-16), facts (FR-33), meta box, conflict detection (FR-27, FR-31), tests.

## Install for a pilot

Zip the `rt-llms-txt/` folder (forward-slash entry paths, as for rt-source-tracker) and upload it in wp-admin. Before activating, turn off Rank Math's LLMs.txt option and make sure no physical `llms.txt` exists in the web root.
