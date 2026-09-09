# The Order Complete Page is unlocked by what Capture handed the browser

The Order Complete Page shows the Customer their own name, number and address, because a Customer
who has just typed them wants to see that they were taken down right. It is addressed by order
number alone, and that address ends up in browser history and Referer headers, so the address
must not be what unlocks those details. What does is a cookie Capture sets in the browser that
placed the Order: encrypted and signed by the framework, holding the order numbers that browser
has placed, living thirty days. A request carrying it sees the Order in full; any other request
sees the Order and not the Customer, which is what the page showed everybody until now.

**Considered options.** Burning the page on first view was rejected because a reload, the back
button and a Facebook in-app browser reopening the tab all count as a second view, so the Customer
who placed the Order thirty seconds ago is the one it strips. A time window after settlement was
rejected because it leaks to anyone with the address inside the window and strips the Customer
outside it, failing both readers. The session was rejected because a gateway sends the Customer
back with a cross-site POST that carries no cookies; that request starts a fresh session and its
response replaces the Customer's, so every online-paid Order would land stripped. Having the
Processing Page re-grant ownership was rejected because its address sits in the gateway's own
logs, which is the reason it prints nothing about the Customer today. A dedicated cookie is left
untouched by the cross-site POST and is sent on the same-origin visit that follows, so cash and
online arrive the same way.

## Consequences

- A stranger with the address is never refused; they are shown less. There is no 404 and no
  login, so a Customer whose cookie is gone still has their order number and totals.
- The Processing Page and the Retry Page are unchanged. Neither reads the cookie, and neither
  prints the Customer.
- Ownership is a browser, not a person. The same Customer on a second device sees the Order
  only, and the cookie is what they are asked for if support ever needs to reason about it.
- The cookie is a list, so a browser that placed several Orders is recognised for each of them.
  It is bounded so it cannot grow without limit; the oldest order numbers fall off first.
