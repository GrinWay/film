mkdir "./bundles/grinway" -p
cd "./bundles/grinway"

git clone "https://github.com/GrinWay/command-bundle.git"
git clone "https://github.com/GrinWay/service-bundle.git"
git clone "https://github.com/GrinWay/env-processor-bundle.git"

cd "./command-bundle"
git fetch origin v1
git checkout -b v1
git checkout v1 -f && git merge origin/v1 -Xtheirs -m'auto update(merge v1)'
cd ".."

cd "./service-bundle"
git fetch origin v1
git checkout -b v1
git checkout v1 -f && git merge origin/v1 -Xtheirs -m'auto update(merge v1)'
cd ".."

cd "./env-processor-bundle"
git fetch origin v1
git checkout -b v1
git checkout v1 -f && git merge origin/v1 -Xtheirs -m'auto update(merge v1)'
cd ".."

cd "../.."
composer install
composer dump-autoload -o
php "./bin/film" "cache:clear" "-q"