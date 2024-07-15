declare -A BUNDLE_NAMES
declare -A BUNDLE_VERSIONS


###> !CHANGE ME! ###

###> BEAUTIFIER ###
CONSOLE_TITLE_COLOR='\033[0;36m'
CONSOLE_NC='\033[0m'
START_INFO_TEXT="INITIALIZATION STARTED"
END_INFO_TEXT="INITIALIZATION END"
###< BEAUTIFIER ###

###> BUNDLES ###
BUNDLES_DIR="bundles"
REP_REMOTE_NAME="origin"
BUNDLE_NAMESPACE="GrinWay"

BUNDLE_NAMES[0]="env-processor-bundle"
BUNDLE_VERSIONS[0]="main"
BUNDLE_FULL_NAME[0]="${BUNDLE_NAMESPACE}/${BUNDLE_NAMES[0]}"

BUNDLE_NAMES[1]="service-bundle"
BUNDLE_VERSIONS[1]="main"
BUNDLE_FULL_NAME[1]="${BUNDLE_NAMESPACE}/${BUNDLE_NAMES[1]}"

BUNDLE_NAMES[2]="command-bundle"
BUNDLE_VERSIONS[2]="main"
BUNDLE_FULL_NAME[2]="${BUNDLE_NAMESPACE}/${BUNDLE_NAMES[2]}"

BUNDLE_NAMES[3]="web-app-bundle"
BUNDLE_VERSIONS[3]="main"
BUNDLE_FULL_NAME[3]="${BUNDLE_NAMESPACE}/${BUNDLE_NAMES[3]}"
###< BUNDLES ###

###< !CHANGE ME! ###


###> ALGO ###
echo -e "\r\n"
echo -e "${CONSOLE_TITLE_COLOR}${START_INFO_TEXT}${CONSOLE_NC}"
echo -e "\r\n"

mkdir "./${BUNDLES_DIR}/${BUNDLE_NAMESPACE}" -p
cd "./${BUNDLES_DIR}/${BUNDLE_NAMESPACE}"

###> CYCLE ###
for k in "${!BUNDLE_NAMES[@]}"
do
	B_FULL_NAME="${BUNDLE_FULL_NAME[${k}]}"
	B_NAME="${BUNDLE_NAMES[${k}]}"
	B_VERSION="${BUNDLE_VERSIONS[${k}]}"
	
	git clone "https://github.com/${B_FULL_NAME}.git" "${B_NAME}"
	
	cd "./${B_NAME}"
	git checkout "${REP_REMOTE_NAME}/${B_VERSION}" -f
	git branch -D "${B_VERSION}"
	git checkout -b "${B_VERSION}" -f
	cd ".."
done
###< CYCLE ###

cd "../.."

composer install
composer dump-autoload -o
php "./bin/film" "assets:install"
php "./bin/film" "cache:clear"

echo -e "\r\n"
echo -e "${CONSOLE_TITLE_COLOR}${END_INFO_TEXT}${CONSOLE_NC}"