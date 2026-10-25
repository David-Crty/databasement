#!/usr/bin/env bash
# Render banner-generator.html to banner-v2.png using headless Chrome.

google-chrome \
    --headless \
    --disable-gpu \
    --hide-scrollbars \
    --window-size=1280,640 \
    --virtual-time-budget=5000 \
    --default-background-color=00000000 \
    --screenshot=banner-v2.png \
    "file://$PWD/banner-generator.html"
