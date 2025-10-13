#!/bin/sh

if [ -z "$husky_skip_init" ]; then
  husky_skip_init=1

  if [ -f "$HOME/.huskyrc" ]; then
    . "$HOME/.huskyrc"
  fi
fi
