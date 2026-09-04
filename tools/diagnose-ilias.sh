#!/usr/bin/env bash
set -u

INPUT_URL="${1:-}"

if [[ -z "$INPUT_URL" ]]; then
  echo "Usage: $0 'https://ilias.example.org'"
  echo "Vous pouvez aussi fournir une URL complete de cours ; le script en extraira la racine du site."
  echo "IMPORTANT : entourez toujours les URL contenant des & avec des quotes simples."
  exit 2
fi

# Detecte le cas typique ou Bash a coupe une URL ILIAS non quotee sur le premier '&'.
# Ex.: ./diagnose-ilias.sh http://host/ilias.php?baseClass=...&cmd=...&ref_id=123
# Dans ce cas, le script ne recoit souvent que la partie avant le premier '&'.
if [[ "$INPUT_URL" == *"/ilias.php?baseClass="* && "$INPUT_URL" != *"ref_id="* ]]; then
  echo "AVERTISSEMENT : l'URL ILIAS semble incomplete (ref_id absent)."
  echo "Cause probable : l'URL contient des '&' et n'a pas ete entouree de quotes."
  echo "Exemple :"
  echo "  $0 'http://host/ilias.php?baseClass=ilrepositorygui&cmd=view&ref_id=123'"
  echo
fi

# Normalise automatiquement une URL de cours/permalink vers scheme://host[:port].
# Ex.: http://host/ilias.php?... ou http://host/goto.php/crs/123 -> http://host
BASE_URL="$(printf '%s' "$INPUT_URL" | sed -E 's#^(https?://[^/]+).*$#\1#')"
BASE_URL="${BASE_URL%/}"

if [[ ! "$BASE_URL" =~ ^https?://[^/]+$ ]]; then
  echo "ERREUR : URL non reconnue : $INPUT_URL"
  echo "Exemple attendu : https://ilias.example.org"
  exit 2
fi

TMPDIR_DIAG="$(mktemp -d)"
trap 'rm -rf "$TMPDIR_DIAG"' EXIT

echo "============================================"
echo " ILIAS2Moodle - Diagnostic ILIAS Phase 1"
echo "============================================"
echo "Date      : $(date -Iseconds 2>/dev/null || date)"
echo "Host      : $(hostname 2>/dev/null || echo unknown)"
echo "Input URL : $INPUT_URL"
echo "Base URL  : $BASE_URL"
if [[ "$INPUT_URL" != "$BASE_URL" && "$INPUT_URL" != "$BASE_URL/" ]]; then
  echo "Info      : l'URL fournie a ete normalisee vers la racine du site."
fi

echo
echo "[1] Python / ILIAS2Moodle"
FOUND_PYTHON=0
for candidate in python3.13 python3.12 python3.11; do
  if command -v "$candidate" >/dev/null 2>&1; then
    echo "$candidate : $($candidate --version 2>&1) ($(command -v "$candidate"))"
    FOUND_PYTHON=1
  fi
done
if command -v python3 >/dev/null 2>&1; then
  echo "python3    : $(python3 --version 2>&1) ($(command -v python3))"
fi
if [[ "$FOUND_PYTHON" -eq 0 ]]; then
  echo "Compatibilité ILIAS2Moodle : NON - Python 3.11+ non détecté"
  echo "Le Python système ne doit pas être remplacé. Installer Python 3.11 en parallèle."
else
  echo "Compatibilité ILIAS2Moodle : OUI"
fi

echo
echo "[2] PHP"
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
echo "[3] Recherche d'une installation ILIAS dans le repertoire courant"
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
echo "[4] Test des endpoints SOAP/WSDL"
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
echo "[5] Informations a relever manuellement"
echo "  - version ILIAS exacte (ex. 10.x)"
echo "  - URL du cours POC"
echo "  - ref_id du cours POC"
echo "  - client_id ILIAS si connu"
echo "  - possibilite ou non de creer un compte technique de lecture"
echo
echo "IMPORTANT : ne partagez jamais mot de passe, token, config.json complet ou secret DB."
