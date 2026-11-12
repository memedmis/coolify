---
type: fixed
---
Deployed only the first branch of a Bitbucket push that updated several branches ([#12524](https://github.com/coollabsio/coolify/issues/12524))

- Each pushed branch deploys with its own commit, and `[skip ci]` or `[skip cd]` skips only the branch whose commits contain it
