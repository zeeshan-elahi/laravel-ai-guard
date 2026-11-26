# Changelog

## 0.2.0

- Tool calls that need human approval are checked by their policy before the pause. A call the policy blocks is no longer sent to a reviewer.
- Calls that resume after an approval are checked again, on the arguments actually being run, including arguments edited with `Decision::edit()`. A blocked call does not run, and is logged as `tool.blocked` with `after_approval: true`.
- New config key `approvals.untrimmed_results` (default `block`): a resumed call to a tool with `onlyReturnFields()` is blocked unless set to `allow`.
- Requires `laravel/ai` 1.4.2 or newer.
- Behaviour change: reviewers no longer see calls the policy would block.

## 0.1.0

- First release.
