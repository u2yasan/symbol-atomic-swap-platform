#!/usr/bin/env sh
set -eu

fail() {
  printf '%s\n' "$1" >&2
  exit 1
}

tracked_sensitive_files=$(
  git ls-files | awk '
    function basename(path, parts) {
      split(path, parts, "/")
      return parts[length(parts)]
    }

    function allowed_env_example(path) {
      return path == ".env.example" || path ~ /\/\.env\.example$/
    }

    {
      name = basename($0)

      if (($0 == ".env" || $0 ~ /\/\.env$/ || $0 ~ /(^|\/)\.env\./) && !allowed_env_example($0)) {
        print $0
        next
      }

      if ($0 ~ /\.(pem|key|p12|pfx)$/) {
        print $0
        next
      }

      if (name ~ /^id_(rsa|dsa|ecdsa|ed25519)$/) {
        print $0
        next
      }

      if ($0 ~ /(^|\/)(secret|secrets|credential|credentials)\.(json|ya?ml|toml|ini|env|txt)$/) {
        print $0
        next
      }
    }
  '
)

if [ -n "$tracked_sensitive_files" ]; then
  printf '%s\n' "Refusing tracked sensitive files:" >&2
  printf '%s\n' "$tracked_sensitive_files" >&2
  exit 1
fi

for dockerignore in .dockerignore symbol-engine/.dockerignore; do
  [ -f "$dockerignore" ] || fail "Missing required Docker ignore file: $dockerignore"
  grep -Fxq '.env' "$dockerignore" || fail "$dockerignore must exclude .env"
  grep -Fxq '.env.*' "$dockerignore" || fail "$dockerignore must exclude .env.*"
done

grep -Fxq '.env' .gitignore || fail ".gitignore must exclude .env"
grep -Fxq '.env.local' .gitignore || fail ".gitignore must exclude .env.local"

# Reject any hard-coded Symbol Engine API token in workflow/compose files. Only
# an empty value or a ${VAR} indirection is permitted, so a committed literal
# can never be copy-pasted into a real deployment (regardless of its entropy).
literal_engine_token_config=$(
  git ls-files '.github/workflows/*.yml' 'docker-compose*.yml' | xargs awk '
    /^[[:space:]]*SYMBOL_ENGINE_API_TOKEN:/ {
      val = $0
      sub(/^[[:space:]]*SYMBOL_ENGINE_API_TOKEN:[[:space:]]*/, "", val)
      sub(/[[:space:]]*(#.*)?$/, "", val)
      gsub(/^"|"$/, "", val)
      if (val == "") next
      if (val ~ /^\$\{/) next
      print FILENAME ":" FNR ": " $0
    }
  '
)

if [ -n "$literal_engine_token_config" ]; then
  printf '%s\n' "Refusing hard-coded Symbol Engine API token in runtime configuration (use \${SYMBOL_ENGINE_API_TOKEN}):" >&2
  printf '%s\n' "$literal_engine_token_config" >&2
  exit 1
fi

printf '%s\n' "Sensitive file policy passed."
