/**
 * @file
 * Behaviors for /quran/my-account: remove-bookmark + add-note actions.
 *
 * The CSRF token is exposed as window.gablvmQfCsrf by the twig template
 * (see gablvm-quran-account.html.twig). All POSTs go through this token.
 */
(function (Drupal, once) {
  'use strict';

  function postJson(url, payload) {
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(Object.assign({ csrf_token: window.gablvmQfCsrf || '' }, payload))
    }).then(function (r) {
      return r.json().then(function (j) {
        return { status: r.status, body: j };
      });
    });
  }

  Drupal.behaviors.gablvmQfBookmarkRemove = {
    attach: function (context) {
      once('qf-bookmark-remove', '.quran-account-bookmark-remove', context).forEach(function (btn) {
        btn.addEventListener('click', function () {
          var id = btn.getAttribute('data-bookmark-id');
          if (!id) return;
          btn.disabled = true;
          postJson('/quran/api/bookmark/remove', { id: id })
            .then(function (res) {
              if (res.status === 200 && res.body && res.body.ok) {
                var li = btn.closest('li');
                if (li) li.parentNode.removeChild(li);
              } else {
                btn.disabled = false;
                btn.textContent = Drupal.t('Could not remove, try again');
              }
            })
            .catch(function () {
              btn.disabled = false;
              btn.textContent = Drupal.t('Network error, try again');
            });
        });
      });
    }
  };

  Drupal.behaviors.gablvmQfNoteCreate = {
    attach: function (context) {
      once('qf-note-form', '#qf-note-form', context).forEach(function (form) {
        form.addEventListener('submit', function (e) {
          e.preventDefault();
          var status = document.getElementById('qf-note-status');
          var submit = document.getElementById('qf-note-submit');
          var surah = parseInt(form.elements.surah.value, 10);
          var verse = parseInt(form.elements.verse.value, 10);
          var body = form.elements.body.value.trim();
          if (!surah || !verse || !body) {
            if (status) status.textContent = Drupal.t('Please fill in surah, verse, and note text.');
            return;
          }
          if (submit) submit.disabled = true;
          if (status) status.textContent = Drupal.t('Saving the note.');
          postJson('/quran/api/note', { surah: surah, verse: verse, body: body })
            .then(function (res) {
              if (submit) submit.disabled = false;
              if (res.status === 200 && res.body && res.body.ok) {
                if (status) status.textContent = Drupal.t('Note saved.');
                form.elements.body.value = '';
                if (res.body.note) {
                  injectNoteIntoList(res.body.note);
                }
              } else if (res.body && res.body.error === 'reconnect_required') {
                if (status) status.textContent = Drupal.t('Notes need an updated permission. Please disconnect and reconnect your Quran.com account, then try again.');
              } else {
                if (status) status.textContent = Drupal.t('Could not save the note, try again.');
              }
            })
            .catch(function () {
              if (submit) submit.disabled = false;
              if (status) status.textContent = Drupal.t('Network error, try again.');
            });
        });
      });
    }
  };

  // Inject a newly-created note into the dashboard list without reloading
  // the page. Mirrors the server-side Twig template for note <li>s and
  // wires the delete behavior on the new button via direct listener.
  function injectNoteIntoList(note) {
    var list = document.getElementById('qf-notes-list');
    var empty = document.getElementById('qf-notes-empty');
    if (!list) return;
    var li = document.createElement('li');
    li.setAttribute('data-note-id', note.id || '');
    if (note.ranges && note.ranges.length) {
      var strong = document.createElement('strong');
      strong.textContent = note.ranges.join(', ');
      li.appendChild(strong);
    }
    var p = document.createElement('p');
    p.textContent = note.body || '';
    li.appendChild(p);
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'quran-btn quran-btn-secondary quran-account-note-remove';
    btn.setAttribute('data-note-id', note.id || '');
    btn.setAttribute('aria-label', Drupal.t('Remove this note'));
    btn.textContent = Drupal.t('Remove');
    btn.addEventListener('click', function () { handleNoteRemove(btn); });
    li.appendChild(btn);
    // Newest first.
    list.insertBefore(li, list.firstChild);
    if (list.hasAttribute('hidden')) list.removeAttribute('hidden');
    if (empty) empty.setAttribute('hidden', 'hidden');
  }

  function handleNoteRemove(btn) {
    var id = btn.getAttribute('data-note-id');
    if (!id) return;
    btn.disabled = true;
    var originalText = btn.textContent;
    btn.textContent = Drupal.t('Removing.');
    postJson('/quran/api/note/remove', { id: id })
      .then(function (res) {
        if (res.status === 200 && res.body && res.body.ok) {
          var li = btn.closest('li');
          if (li) li.parentNode.removeChild(li);
          var list = document.getElementById('qf-notes-list');
          var empty = document.getElementById('qf-notes-empty');
          if (list && list.children.length === 0) {
            list.setAttribute('hidden', 'hidden');
            if (empty) empty.removeAttribute('hidden');
          }
        } else {
          btn.disabled = false;
          btn.textContent = Drupal.t('Could not remove, try again');
          setTimeout(function () { btn.textContent = originalText; }, 2500);
        }
      })
      .catch(function () {
        btn.disabled = false;
        btn.textContent = Drupal.t('Network error, try again');
        setTimeout(function () { btn.textContent = originalText; }, 2500);
      });
  }

  Drupal.behaviors.gablvmQfNoteRemove = {
    attach: function (context) {
      once('qf-note-remove', '.quran-account-note-remove', context).forEach(function (btn) {
        btn.addEventListener('click', function () { handleNoteRemove(btn); });
      });
    }
  };

})(Drupal, once);
