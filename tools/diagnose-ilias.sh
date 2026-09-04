#!/usr/bin/env bash
set -u

BASE_URL="${1:-}"

if [[ -z "$BASE_URL" ]]; then
  echo "Usage: $0 https://ilias.example.org"
  exit 2
fi

BASE_URL="${BASE_URL%/}"
TMPDIR_DIAG="$(mktemp -d)"
trap 'rm -rf "$TMPDIR_DIAG"' EXIT

echo "============================================"
echo " ILIAS2Moodle - Diagnostic ILIAS Phase 1"
echo "============================================"
echo "Date      : $(date -Iseconds 2>/dev/null || date)"
echo "Host      : $(hostname 2>/dev/null || echo unknown)"
echo "Base URL  : $BASE_URL"
echo

echo "[1] PHP"
if command -v php >/dev/null 2>&1; then
  php -v | head -n 1
  if php -m 2>/dev/null | grep -qi '^soap$'; then
    echo "SOAP PHP  : present"
  else
    echo "SOAP PHP  : absent ou non visible dans ce contexte"
  fi
else
  echo "PHP       : commande non trouvee (ce n'est pas bloquant si le test est lance depuis un poste client)"
fi

echo

echo "[2] Recherche d'une installation ILIAS dans le repertoire courant"
if [[ -f "cli/setup.php" ]]; then
  echo "ILIAS     : cli/setup.php trouve"
  if command -v php >/dev/null 2>&1; then
    echo "Commande utile (ne pas coller ici un config.json contenant des secrets) :"
    echo "  php cli/setup.php status"
  fi
else
  echo "ILIAS     : cli/setup.php non trouve dans $(pwd)"
  echo "            Normal si ce script est lance depuis un poste client."
fi

echo

echo "[3] Test des endpoints SOAP/WSDL"
FOUND=0

for PATH_WSDL in "/soap/server.php?wsdl" "/public/soap/server.php?wsdl"; do
  URL="${BASE_URL}${PATH_WSDL}"
  OUT="${TMPDIR_DIAG}/wsdl-$(echo "$PATH_WSDL" | tr '/?' '__').xml"
  ERR="${TMPDIR_DIAG}/curl.err"

  HTTP_CODE=$(curl -sS -L --connect-timeout 10 --max-time 30 \
    -o "$OUT" -w "%{http_code}" "$URL" 2>"$ERR" || true)

  echo "URL       : $URL"
  echo "HTTP      : ${HTTP_CODE:-000}"

  if [[ -s "$ERR" ]]; then
    echo "curl      : $(tr '\n' ' ' < "$ERR")"
  fi

  if [[ "${HTTP_CODE:-000}" == "200" ]] && \
     grep -Eqi '<([^:>]+:)?definitions|<([^:>]+:)?description' "$OUT"; then
    echo "WSDL      : detecte"
    FOUND=1

    echo "Operations SOAP detectees :"
    grep -Eo 'operation[[:space:]]+name="[^"]+"' "$OUT" \
      | sed -E 's/.*name="([^"]+)"/  - \1/' \
      | sort -u \
      | head -n 200 || true
  else
    echo "WSDL      : non detecte"
    if [[ -s "$OUT" ]]; then
      echo "Reponse   : $(head -c 180 "$OUT" | tr '\n' ' ')"
      echo
    fi
  fi
  echo
 done

if [[ "$FOUND" -eq 0 ]]; then
  cat <<'EOF'
Aucun WSDL SOAP n'a ete detecte.
Ce resultat ne signifie pas que SOAP est desactive : l'endpoint peut etre
restreint au niveau Apache/Nginx ou utilise une URL differente.
Ne modifiez rien pour l'instant ; conservez simplement cette sortie.
EOF
else
  echo "Au moins un endpoint SOAP/WSDL est accessible."
fi

echo
echo "[4] Informations a relever manuellement"
echo "  - version ILIAS exacte (ex. 10.x)"
echo "  - URL du cours POC"
echo "  - ref_id du cours POC"
echo "  - client_id ILIAS si connu"
echo "  - possibilite ou non de creer un compte technique de lecture"
echo
echo "IMPORTANT : ne partagez jamais mot de passe, token, config.json complet ou secret DB."
