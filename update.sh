CONSOLE_TITLE_COLOR='\033[0;36m'
CONSOLE_NC='\033[0m'

echo -e "\r\n"
echo -e "${CONSOLE_TITLE_COLOR}UPDATING STARTED${CONSOLE_NC}"
echo -e "\r\n"

git fetch origin main
git checkout main -f && git merge origin/main -Xtheirs -m'update(auto merge with the origin/main branch)'

./init.sh

echo -e "\r\n"
echo -e "${CONSOLE_TITLE_COLOR}UPDATING FINISHED :)${CONSOLE_NC}"