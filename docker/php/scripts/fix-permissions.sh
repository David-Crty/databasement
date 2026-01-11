#!/bin/bash

# From https://raw.githubusercontent.com/linuxserver/docker-mods/mod-scripts/lsiown.v1

# Version 1
# 2024-06-08 - Initial Version

LSIOWN_SCRIPT_VER="1.20240608"

MAXDEPTH=("-maxdepth" "0")
OPTIONS=()
while getopts RcfvhHLP OPTION
do
    if [[ "${OPTION}" != "?" && "${OPTION}" != "R" ]]; then
        OPTIONS+=("-${OPTION}")
    fi
    if [[ "${OPTION}" = "R" ]]; then
        MAXDEPTH=()
    fi
done

shift $((OPTIND - 1))
OWNER=$1
IFS=: read -r USER GROUP <<< "${OWNER}"
if [[ -z "${GROUP}" ]]; then
    printf '**** Permissions could not be set. Group is missing or incorrect, expecting user:group. ****\n'
    exit 0
fi

ERROR='**** Permissions could not be set. This is probably because your volume mounts are remote or read-only. ****\n**** The app may not work properly and we will not provide support for it. ****\n'
PATHS=("${@:2}")
/usr/bin/find "${PATHS[@]}" "${MAXDEPTH[@]}" ! -xtype l \( ! -group "${GROUP}" -o ! -user "${USER}" \) -exec chown "${OPTIONS[@]}" "${USER}":"${GROUP}" {} + || printf "${ERROR}"
