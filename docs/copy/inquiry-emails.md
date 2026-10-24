# Inquiry chain: customer-visible copy (final, adversarial review passed)

Ticket #33 (IMP-8), 2026-10-24. The AGENTS.md §5 rule — customer-visible
wording goes through adversarial review, the final is archived — applied to
every EN string the inquiry chain emits. The review's verdicts and required
changes are recorded below; the final texts are the ones the code carries.

The WhatsApp prefills follow spec 07 appendix B (branch
research/spec-inquiry-chain) as revised by ticket #7; W1's wording and the
Ref line are the spec's own, kept unchanged.

## Form

- Pattern note: "This pattern is attached to your inquiry."
- Name / Email labels and errors, Company (optional), Submit ("Send
  inquiry"), WhatsApp alternate ("Send via WhatsApp instead"), honeypot
  label ("Leave this field empty"): unchanged from the draft.
- Message label with a pattern: "Message (optional — the pattern is already
  attached)"; without one: "Message" (required). Hint: "Quantities,
  dimensions, timeline, finish preferences — anything you already have."
- Form note: "The WhatsApp button opens a chat pre-filled with your details
  and this pattern. Choosing WhatsApp does not submit this form."
- Turnstile expired: "Verification expired. Please try again."
- Turnstile error: "Verification could not run. Please refresh the page and
  try again."
- Notices — validation: "Please complete the highlighted fields."; rate
  limit: "You're sending inquiries too quickly. Please wait a few minutes
  and try again."; nonce: "Your form session expired. Please reload the
  page and try again."; Turnstile rejection: "Verification failed. Please
  refresh the page and try again."; spam trap: "We could not accept this
  submission. If this keeps happening, reach us on WhatsApp or by email.";
  storage failure: "Your inquiry could not be saved right now. Please try
  again, or reach us on WhatsApp or by email."

## WhatsApp prefilled texts

- W1: "Hi, I'm interested in a custom metal divider screen project."
- Pattern CTA: W1, then "Pattern: {name} ({code})", then "Ref: {page}/{module}/whatsapp".
- Form button (assembled live in the browser): "Hi, I'm {name} ({company})."
  when a name is filled, then "I'd like a quote for this pattern:" and
  "{name} ({code})", then the message when filled, then "Ref: {page}/{module}/{button}".

## Internal notification (to sales@sunriverinverter.com, info@metal-divider.com)

Subject: "New inquiry: {pattern} — {name}" (without a pattern:
"New inquiry: {name}").

    A new inquiry arrived through the inquiry form.

    Pattern: {name} ({code})
    Pattern page: {url}
    Submitted: {Y-m-d H:i:s} UTC
    Source: {page}/{module}/{button}
    Verification: verified | not configured

    Name: {name}
    Email: {email}
    Company: {company | (none)}
    Message:
    {message | (none)}

    Open the inquiry in the dashboard:
    {edit url}

    -- metal-divider.com inquiries

## Customer confirmation (to the customer)

Subject: "We've received your inquiry about {pattern}" (without a pattern:
"We've received your inquiry").

    Hello {name},

    Thank you for your inquiry about {pattern} ({code}) — this email
    confirms we have received it.

    Our team replies within one business day with pattern availability,
    material options and pricing. If you sent dimensions, quantities or a
    timeline, we will address them in that reply. If you have drawings or
    site photos, attaching them to your reply helps us quote faster.

    Need to reach us sooner? Reply directly to this email, or use:
    - WhatsApp: +852 6473 1687 — https://wa.me/85264731687
    - Email: info@metal-divider.com

    Kind regards,
    The MetalDivider team
    metal-divider.com

## Review record (2026-10-24, adversarial pass)

Applied: the "attached" wording consistency; the WhatsApp form note's
correction (choosing WhatsApp does not submit this form); the rate-limit
notice reworded ("too quickly", not "from your address"); the display
number grouped as +852 6473 1687 (the draft and the prototype footer both
carried a mis-grouped form — the wa.me URL's digits were always correct);
the internal mail's pattern-independent first line, the "not configured"
verification value, "(none)" for empty fields, and the
"metal-divider.com inquiries" footer; the confirmation's merged receipt
sentence, full-name greeting, reply invitation, and the drawings-photos
line; the message field's required switch without a pattern; the WhatsApp
form text's introduction skipped when the name is empty. Kept after
consideration: the Ref line inside customer-visible WhatsApp texts
(spec-locked, known and accepted); "one business day" (the prototype's
approved promise); "material options" (304/316 grades are the options).
