// Reference implementation of the BCM Hub request signature (docs/HUB-PROTOCOL.md).
// WebCrypto only, so the same code runs in a Cloudflare Worker and in Node 20+.
//
// CLI (used by tests/hub/run.php):
//   node sign.mjs keygen            -> {"public":"<b64url 32 bytes>","private_pkcs8":"<base64>"}
//   echo '<json>' | node sign.mjs sign
//      json: {private_pkcs8, key_id, host, actor, body, route?, time?, nonce?,
//             approval?: {private_pkcs8, key_id, time?}}
//      -> {"headers": {...}}

const enc = new TextEncoder();

export const PROTOCOL = 'BCM-HUB-V1';
export const APPROVAL = 'BCM-HUB-APPROVAL-V1';
export const ROUTE = '/bcm-agency/v1/hub';

export function b64url(bytes) {
  let s = '';
  for (const b of new Uint8Array(bytes)) s += String.fromCharCode(b);
  return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function b64ToBytes(b64) {
  const bin = atob(b64.trim());
  const out = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
  return out;
}

export async function sha256Hex(text) {
  const d = await crypto.subtle.digest('SHA-256', enc.encode(text));
  return [...new Uint8Array(d)].map((b) => b.toString(16).padStart(2, '0')).join('');
}

export function randomNonce() {
  return b64url(crypto.getRandomValues(new Uint8Array(24))); // 32 chars, 192 bits
}

// Secrets are stored as base64 PKCS#8; trim() tolerates a trailing newline from a pipe.
export async function importSigningKey(pkcs8B64) {
  return crypto.subtle.importKey('pkcs8', b64ToBytes(pkcs8B64), { name: 'Ed25519' }, false, ['sign']);
}

export function requestMessage({ method, host, route, keyId, time, nonce, actor, bodyHash }) {
  return [PROTOCOL, method, host, route, keyId, String(time), nonce, actor, bodyHash].join('\n');
}

export function approvalMessage({ keyId, host, nonce, actor, time, bodyHash }) {
  return [APPROVAL, keyId, host, nonce, actor, String(time), bodyHash].join('\n');
}

async function sign(key, message) {
  return b64url(await crypto.subtle.sign({ name: 'Ed25519' }, key, enc.encode(message)));
}

/**
 * Headers for one hub request. `host` must be the site's host exactly as it
 * appears in the URL the request is sent to (lower case, no port).
 */
export async function signRequest({ key, keyId, host, actor, body, route = ROUTE, time, nonce }) {
  time = time ?? Math.floor(Date.now() / 1000);
  nonce = nonce ?? randomNonce();
  const bodyHash = await sha256Hex(body);
  const message = requestMessage({ method: 'POST', host, route, keyId, time, nonce, actor, bodyHash });
  return {
    'X-BCM-Hub-Key': keyId,
    'X-BCM-Hub-Time': String(time),
    'X-BCM-Hub-Nonce': nonce,
    'X-BCM-Hub-Actor': actor,
    'X-BCM-Hub-Signature': await sign(key, message),
  };
}

/** Second-confirmation headers, signed by the approval key over the same request. */
export async function signApproval({ key, keyId, host, nonce, actor, body, time }) {
  time = time ?? Math.floor(Date.now() / 1000);
  const bodyHash = await sha256Hex(body);
  return {
    'X-BCM-Hub-Approval-Key': keyId,
    'X-BCM-Hub-Approval-Time': String(time),
    'X-BCM-Hub-Approval': await sign(key, approvalMessage({ keyId, host, nonce, actor, time, bodyHash })),
  };
}

async function readStdin() {
  const chunks = [];
  for await (const c of process.stdin) chunks.push(c);
  return Buffer.concat(chunks).toString('utf8');
}

async function cli() {
  const cmd = process.argv[2];
  if (cmd === 'keygen') {
    const kp = await crypto.subtle.generateKey({ name: 'Ed25519' }, true, ['sign', 'verify']);
    const pub = await crypto.subtle.exportKey('raw', kp.publicKey);
    const priv = await crypto.subtle.exportKey('pkcs8', kp.privateKey);
    process.stdout.write(JSON.stringify({ public: b64url(pub), private_pkcs8: Buffer.from(priv).toString('base64') }));
    return;
  }
  if (cmd === 'sign') {
    const o = JSON.parse(await readStdin());
    const key = await importSigningKey(o.private_pkcs8);
    const headers = await signRequest({ key, keyId: o.key_id, host: o.host, actor: o.actor, body: o.body, route: o.route, time: o.time, nonce: o.nonce });
    if (o.approval) {
      const akey = await importSigningKey(o.approval.private_pkcs8);
      Object.assign(headers, await signApproval({ key: akey, keyId: o.approval.key_id, host: o.host, nonce: headers['X-BCM-Hub-Nonce'], actor: o.actor, body: o.body, time: o.approval.time }));
    }
    process.stdout.write(JSON.stringify({ headers }));
    return;
  }
  process.stderr.write('usage: node sign.mjs keygen | sign (JSON on stdin)\n');
  process.exit(2);
}

if (typeof process !== 'undefined' && process.argv && import.meta.url === `file://${process.argv[1]}`) {
  cli().catch((e) => { process.stderr.write(String(e && e.stack || e) + '\n'); process.exit(1); });
}
