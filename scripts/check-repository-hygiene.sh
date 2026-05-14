#!/usr/bin/env sh
set -eu

tracked_generated_files=$(
  git ls-files | awk '
    $0 ~ /(^|\/)(node_modules|vendor|dist|build|coverage)\// {
      print $0
      next
    }

    $0 ~ /^drupal\/web\/(core|libraries|modules\/contrib|profiles\/contrib|themes\/contrib)\// {
      print $0
      next
    }

    $0 ~ /^drupal\/web\/sites\/[^\/]+\/files\// {
      print $0
      next
    }

    $0 ~ /^drupal\/web\/sites\/[^\/]+\/settings\.(local\.)?php$/ {
      print $0
      next
    }
  '
)

if [ -n "$tracked_generated_files" ]; then
  printf '%s\n' "Refusing tracked generated or installed files:" >&2
  printf '%s\n' "$tracked_generated_files" >&2
  exit 1
fi

required_gitignore_patterns='
/node_modules/
/vendor/
/symbol-engine/node_modules/
/symbol-engine/dist/
/drupal/vendor/
/drupal/web/sites/*/files/
/drupal/web/sites/*/settings.php
/drupal/web/sites/*/settings.local.php
'

printf '%s\n' "$required_gitignore_patterns" | while IFS= read -r pattern; do
  [ -n "$pattern" ] || continue
  grep -Fxq "$pattern" .gitignore || {
    printf '%s\n' ".gitignore must contain $pattern" >&2
    exit 1
  }
done

printf '%s\n' "Repository hygiene policy passed."
