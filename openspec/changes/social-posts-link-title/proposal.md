# Proposal: social-posts-link-title

## Why

pl8 on board PqSocial (1 Nov): the social posts index draws its titles as plain bold text (700), while the board
draws the default row title, an underlined 15/600 link.

## What changes

- The social posts index drops `rowTitle: "plain"`, so its titles are the default row links.

## Not in this change

- The posts table's other differences (pill, date ink, number of posts): nc-vue and data items.
