const RECIPIENT_EMAIL = 'asso.unisonges@gmail.com';
const COUNTER_KEY = 'contact_sequence_id';
const ALLOWED_SUBJECTS = [
  'Cours',
  'Stages',
  'D’Jam',
  'À propos de l’Orchestre des rêveurs',
  'Autre'
];

function doPost(e) {
  try {
    const params = (e && e.parameter) ? e.parameter : {};

    const subjectChoiceRaw = cleanSingleLine(params.subjectChoice, 120);
    const subjectChoice = ALLOWED_SUBJECTS.indexOf(subjectChoiceRaw) >= 0 ? subjectChoiceRaw : 'Autre';
    const otherTitle = cleanSingleLine(params.otherTitle, 120);
    const fullName = cleanSingleLine(params.fullName, 120);
    const email = cleanSingleLine(params.email, 254);
    const phone = cleanSingleLine(params.phone, 40);
    const pageUrl = cleanSingleLine(params.pageUrl, 2000);
    const userAgent = cleanSingleLine(params.userAgent, 2000);
    const message = cleanMultiline(params.message, 40000);

    const honeypot = cleanSingleLine(params.company || params.honeypot, 120);
    if (honeypot) {
      return jsonResponse({ ok: true });
    }

    const id = nextSequenceId();
    const formattedId = '#' + ('0000' + id).slice(-4);

    const subjectLabel = subjectChoice || 'Autre';
    const subjectSuffix = subjectChoice === 'Autre' && otherTitle
      ? ' — ' + cleanSingleLine(otherTitle, 80)
      : '';
    const mailSubject = cleanSingleLine('[Uni-Songes] ' + formattedId + ' ' + subjectLabel + subjectSuffix, 250);

    const body = [
      'Numéro : ' + id,
      'Objet : ' + subjectLabel + (subjectChoice === 'Autre' && otherTitle ? ' — ' + otherTitle : ''),
      'Nom : ' + (fullName || 'Non renseigné'),
      'Email : ' + (email || 'Non renseigné'),
      'Téléphone : ' + (phone || 'Non renseigné'),
      'Page : ' + (pageUrl || 'Non renseigné'),
      'User-Agent : ' + (userAgent || 'Non renseigné'),
      '',
      'Message :',
      message || '(vide)'
    ].join('\n');

    const mailOptions = { name: 'Uni-Songes Contact' };
    if (isValidEmail(email)) {
      mailOptions.replyTo = email;
    }

    MailApp.sendEmail(RECIPIENT_EMAIL, mailSubject, body, mailOptions);

    return jsonResponse({ ok: true, id: id });
  } catch (err) {
    return jsonResponse({ ok: false });
  }
}

function nextSequenceId() {
  const lock = LockService.getScriptLock();
  lock.waitLock(30000);
  try {
    const props = PropertiesService.getScriptProperties();
    const current = parseInt(props.getProperty(COUNTER_KEY) || '0', 10);
    const next = isNaN(current) ? 1 : current + 1;
    props.setProperty(COUNTER_KEY, String(next));
    return next;
  } finally {
    lock.releaseLock();
  }
}

function cleanSingleLine(value, maxLen) {
  if (typeof value !== 'string') {
    return '';
  }
  const normalized = value.replace(/[\r\n\t]+/g, ' ').replace(/\s+/g, ' ').trim();
  return truncate(normalized, maxLen);
}

function cleanMultiline(value, maxLen) {
  if (typeof value !== 'string') {
    return '';
  }
  const normalized = value.replace(/\r\n?/g, '\n').trim();
  return truncate(normalized, maxLen);
}

function truncate(value, maxLen) {
  if (!maxLen || maxLen <= 0 || value.length <= maxLen) {
    return value;
  }
  return value.slice(0, maxLen);
}

function isValidEmail(value) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value || '');
}

function jsonResponse(payload) {
  return ContentService
    .createTextOutput(JSON.stringify(payload))
    .setMimeType(ContentService.MimeType.JSON);
}
