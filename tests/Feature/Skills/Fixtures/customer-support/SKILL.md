---
name: customer-support
description: Handle customer support inquiries, ticket routing, and response templates. Use when assisting with customer service, support tickets, or customer communication.
license: MIT
compatibility: Requires access to the ticketing system
allowed-tools: Bash(curl:*) Read
metadata:
  author: test-author
  version: "1.0"
---

# Customer Support Skill

## When to use this skill

Use this skill whenever handling:
- Customer support inquiries
- Ticket prioritization and routing
- Response templates for common issues
- Escalation procedures

## Support tiers

### Priority levels

1. **Critical** - System down, blocking all users
2. **High** - Major feature broken, affecting multiple users
3. **Medium** - Minor feature issue, workaround available
4. **Low** - Enhancement requests, questions

### Response time SLAs

- Critical: 1 hour
- High: 4 hours
- Medium: 24 hours
- Low: 48 hours

## Ticket triage

Use the included script for automated ticket routing:

```bash
scripts/triage.sh
```

See [the response templates](references/TEMPLATES.md) for standard replies.

## Common issues

### Account access

For password reset or login issues:
1. Verify user identity
2. Send password reset link
3. Check for account lockout
