const defaultFilters = {
  score: '0.35',
  strategie: '',
  datum: '',
};

const state = {
  items: [],
  meta: null,
  loading: false,
  error: null,
  filters: { ...defaultFilters },
};

let slideshowRefreshTimer = null;

const numberFormatter = new Intl.NumberFormat('de-DE');
const dateFormatter = new Intl.DateTimeFormat('de-DE', {
  day: '2-digit',
  month: 'short',
  year: 'numeric',
});
const dateTimeFormatter = new Intl.DateTimeFormat('de-DE', {
  day: '2-digit',
  month: 'short',
  year: 'numeric',
  hour: '2-digit',
  minute: '2-digit',
});

document.title = 'Rückblick-Galerie';

let filterForm = null;
let scoreInput = null;
let scoreValue = null;
let strategySelect = null;
let dateInput = null;
let applyButton = null;
let resetButton = null;
let statusLine = null;
let cardsContainer = null;

const lightbox = createLightbox();

const appContainer = document.querySelector('#app');
if (appContainer instanceof HTMLElement) {
  initialiseApp(appContainer);
} else {
  console.error('Konnte die Anwendung nicht initialisieren: Container "#app" fehlt.');
}

function initialiseApp(app) {
  app.innerHTML = `
    <section class="toolbar">
      <div>
        <h1>Rückblick-Galerie</h1>
        <p class="subtitle">Entdecke kuratierte Highlights aus deiner Mediathek.</p>
      </div>
      <div class="status-line" data-status>Starte Ladevorgang …</div>
      <form class="filter" data-filter>
        <label>
          Score ab <strong data-score-value>0.35</strong>
          <input type="range" min="0" max="1" step="0.05" name="score" value="0.35" aria-label="Minimaler Score" />
        </label>
        <label>
          Strategie
          <select name="strategie" aria-label="Strategiefilter">
            <option value="">Alle Strategien</option>
          </select>
        </label>
        <label>
          Stichtag
          <input type="date" name="datum" aria-label="Datumsfilter" />
        </label>
        <div class="filter-actions">
          <button type="button" data-apply>Aktualisieren</button>
          <button type="button" data-reset>Zurücksetzen</button>
        </div>
      </form>
    </section>
    <section class="cards" data-cards></section>
  `;

  const form = app.querySelector('[data-filter]');
  const status = app.querySelector('[data-status]');
  const cards = app.querySelector('[data-cards]');

  if (!(form instanceof HTMLFormElement) || !(status instanceof HTMLElement) || !(cards instanceof HTMLElement)) {
    console.error('Konnte die Anwendung nicht initialisieren: erforderliche DOM-Elemente fehlen.');

    return;
  }

  filterForm = form;
  statusLine = status;
  cardsContainer = cards;

  const score = filterForm.querySelector('input[name="score"]');
  const scoreDisplay = filterForm.querySelector('[data-score-value]');
  const strategy = filterForm.querySelector('select[name="strategie"]');
  const date = filterForm.querySelector('input[name="datum"]');
  const apply = filterForm.querySelector('[data-apply]');
  const reset = filterForm.querySelector('[data-reset]');

  if (!(score instanceof HTMLInputElement)
    || !(scoreDisplay instanceof HTMLElement)
    || !(strategy instanceof HTMLSelectElement)
    || !(date instanceof HTMLInputElement)
    || !(apply instanceof HTMLButtonElement)
    || !(reset instanceof HTMLButtonElement)
  ) {
    console.error('Konnte die Anwendung nicht initialisieren: Filtersteuerelemente fehlen oder sind ungültig.');

    return;
  }

  scoreInput = score;
  scoreValue = scoreDisplay;
  strategySelect = strategy;
  dateInput = date;
  applyButton = apply;
  resetButton = reset;

  setFormFromState();
  updateScoreLabel();

  scoreInput.addEventListener('input', () => {
    updateScoreLabel();
  });

  scoreInput.addEventListener('change', () => {
    applyFiltersFromForm();
    fetchFeed();
  });

  strategySelect.addEventListener('change', () => {
    applyFiltersFromForm();
    fetchFeed();
  });

  dateInput.addEventListener('change', () => {
    applyFiltersFromForm();
    fetchFeed();
  });

  applyButton.addEventListener('click', () => {
    applyFiltersFromForm();
    fetchFeed();
  });

  resetButton.addEventListener('click', () => {
    state.filters = { ...defaultFilters };
    setFormFromState();
    updateScoreLabel();
    fetchFeed();
  });

  updateStatus();
  renderItems();
  fetchFeed();
}

async function fetchFeed() {
  state.loading = true;
  state.error = null;
  updateStatus();

  const params = new URLSearchParams();
  const scoreValue = Number.parseFloat(state.filters.score);
  if (!Number.isNaN(scoreValue) && scoreValue > 0) {
    params.set('score', scoreValue.toString());
  }

  if (state.filters.strategie) {
    params.set('strategie', state.filters.strategie);
  }

  if (state.filters.datum) {
    params.set('datum', state.filters.datum);
  }

  params.set('limit', '24');

  try {
    const response = await fetch(`/api/feed?${params.toString()}`, {
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    });

    if (!response.ok) {
      let message = `Fehler ${response.status}`;
      try {
        const body = await response.json();
        if (body && typeof body.error === 'string' && body.error !== '') {
          message += `: ${body.error}`;
        }
      } catch (error) {
        console.warn('Konnte Fehlermeldung nicht parsen', error);
      }

      throw new Error(message);
    }

    const payload = await response.json();
    state.items = Array.isArray(payload.items) ? payload.items : [];
    state.meta = payload.meta ?? null;

    if (state.meta && Array.isArray(state.meta.verfuegbareStrategien)) {
      populateStrategyOptions(state.meta.verfuegbareStrategien);
    }

    if (state.meta && Array.isArray(state.meta.verfuegbareStrategien)) {
      if (!state.meta.verfuegbareStrategien.includes(state.filters.strategie)) {
        state.filters.strategie = '';
      }
    }

    setFormFromState();
    updateScoreLabel();
  } catch (error) {
    console.error('Feed-Ladevorgang fehlgeschlagen', error);
    state.error = error instanceof Error ? error.message : 'Unbekannter Fehler beim Laden des Feeds';
  } finally {
    state.loading = false;
    updateStatus();
    renderItems();
  }
}

function renderItems() {
  if (!(cardsContainer instanceof HTMLElement)) {
    return;
  }

  cardsContainer.textContent = '';

  if (!state.items || state.items.length === 0) {
    const empty = document.createElement('div');
    empty.className = 'empty-state';
    empty.textContent = state.loading
      ? 'Rückblicke werden geladen …'
      : 'Keine Rückblicke für die aktuellen Filter gefunden.';
    cardsContainer.appendChild(empty);

    return;
  }

  let hasPendingSlideshows = false;

  state.items.forEach((item) => {
    if (item && typeof item === 'object' && item.slideshow && item.slideshow.status === 'in_erstellung') {
      hasPendingSlideshows = true;
    }

    cardsContainer.appendChild(createCard(item));
  });

  if (hasPendingSlideshows) {
    if (slideshowRefreshTimer === null) {
      slideshowRefreshTimer = window.setTimeout(() => {
        slideshowRefreshTimer = null;
        fetchFeed();
      }, 8000);
    }
  } else if (slideshowRefreshTimer !== null) {
    window.clearTimeout(slideshowRefreshTimer);
    slideshowRefreshTimer = null;
  }
}

function createCard(item) {
  const card = document.createElement('article');
  card.className = 'card';

  const header = document.createElement('div');
  header.className = 'card-header';

  const title = document.createElement('h2');
  title.textContent = item.titel ?? 'Ohne Titel';
  header.appendChild(title);

  const subtitle = document.createElement('p');
  subtitle.textContent = item.untertitel ?? '';
  header.appendChild(subtitle);

  card.appendChild(header);

  const badges = document.createElement('div');
  badges.className = 'badges';

  if (item.gruppe) {
    const group = document.createElement('span');
    group.textContent = formatLabel(item.gruppe);
    badges.appendChild(group);
  }

  if (item.algorithmus) {
    const algo = document.createElement('span');
    algo.textContent = formatLabel(item.algorithmus);
    badges.appendChild(algo);
  }

  const scoreBadge = document.createElement('span');
  scoreBadge.textContent = `Score ${formatScore(item.score)}`;
  badges.appendChild(scoreBadge);

  card.appendChild(badges);

  const timelineText = buildTimeline(item.zeitspanne);
  if (timelineText) {
    const timeline = document.createElement('div');
    timeline.className = 'timeline';
    timeline.textContent = `Zeitraum: ${timelineText}`;
    card.appendChild(timeline);
  }

  const slideshow = createSlideshowSection(item);
  if (slideshow) {
    card.appendChild(slideshow);
  }

  const gallery = document.createElement('div');
  gallery.className = 'gallery';

  const images = Array.isArray(item.galerie) && item.galerie.length > 0
    ? item.galerie
    : (item.cover
        ? [{
            mediaId: item.coverMediaId,
            thumbnail: item.cover,
            aufgenommenAm: item.coverAufgenommenAm ?? null,
          }]
        : []);

  if (images.length === 0) {
    const placeholder = document.createElement('figure');
    placeholder.className = 'empty';
    const note = document.createElement('figcaption');
    note.textContent = 'Keine Vorschau verfügbar';
    placeholder.appendChild(note);
    gallery.appendChild(placeholder);
  } else {
    images.forEach((image) => {
      if (!image || typeof image.thumbnail !== 'string' || image.thumbnail === '') {
        return;
      }

      const figure = document.createElement('figure');
      figure.classList.add('is-interactive');
      figure.tabIndex = 0;
      figure.setAttribute('role', 'button');

      const img = document.createElement('img');
      img.src = image.thumbnail;
      const takenAtLabel = formatDateTime(image.aufgenommenAm);
      const mediaIdLabel = image.mediaId ? `Medien-ID ${image.mediaId}` : 'Medienvorschau';
      img.alt = takenAtLabel ? `${mediaIdLabel}, aufgenommen am ${takenAtLabel}` : mediaIdLabel;
      img.loading = 'lazy';
      img.decoding = 'async';
      if (takenAtLabel) {
        img.title = `Aufgenommen am ${takenAtLabel}`;
      }
      figure.appendChild(img);

      const captionText = takenAtLabel ? `Aufgenommen am ${takenAtLabel}` : '';
      figure.setAttribute('aria-label', captionText !== '' ? captionText : img.alt);

      if (takenAtLabel) {
        const caption = document.createElement('figcaption');
        caption.textContent = captionText;
        figure.appendChild(caption);
      }

      const activateLightbox = () => {
        if (lightbox.isVisible() && lightbox.getCurrentSource() === image.thumbnail) {
          lightbox.hide();

          return;
        }

        lightbox.show(image.thumbnail, img.alt, captionText, figure);
      };

      figure.addEventListener('click', activateLightbox);
      figure.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          activateLightbox();
        }
      });

      gallery.appendChild(figure);
    });
  }

  card.appendChild(gallery);

  return card;
}

function createSlideshowSection(item) {
  if (!item || typeof item !== 'object') {
    return null;
  }

  const data = item.slideshow;
  if (!data || typeof data.status !== 'string') {
    return null;
  }

  const container = document.createElement('div');
  container.className = 'slideshow';

  if (data.status === 'bereit' && typeof data.url === 'string' && data.url !== '') {
    const video = document.createElement('video');
    video.className = 'slideshow__video';
    video.controls = true;
    video.preload = 'metadata';
    video.playsInline = true;
    video.src = data.url;
    video.setAttribute('aria-label', 'Video-Rückblick abspielen');
    if (typeof item.cover === 'string' && item.cover !== '') {
      video.poster = item.cover;
    }
    container.appendChild(video);

    if (typeof data.dauerProBildSekunden === 'number' && Number.isFinite(data.dauerProBildSekunden)) {
      const meta = document.createElement('p');
      meta.className = 'slideshow__meta';
      meta.textContent = `Bilddauer: ${formatSeconds(data.dauerProBildSekunden)} s`;
      container.appendChild(meta);
    }

    return container;
  }

  const status = document.createElement('div');
  status.className = 'slideshow__status';

  if (data.status === 'in_erstellung') {
    status.textContent = 'Video wird erstellt …';
  } else if (data.status === 'fehlgeschlagen') {
    status.textContent = typeof data.meldung === 'string' && data.meldung !== ''
      ? data.meldung
      : 'Video konnte nicht erstellt werden.';
  } else {
    status.textContent = typeof data.meldung === 'string' && data.meldung !== ''
      ? data.meldung
      : 'Kein Video verfügbar.';
  }

  container.appendChild(status);

  return container;
}

function createLightbox() {
  const overlay = document.createElement('div');
  overlay.className = 'lightbox';
  overlay.hidden = true;
  overlay.setAttribute('role', 'dialog');
  overlay.setAttribute('aria-modal', 'true');
  overlay.setAttribute('aria-label', 'Bildvorschau');

  const closeButton = document.createElement('button');
  closeButton.type = 'button';
  closeButton.className = 'lightbox__close';
  closeButton.setAttribute('aria-label', 'Lightbox schließen');
  closeButton.textContent = 'Schließen';

  const figure = document.createElement('figure');
  figure.className = 'lightbox__figure';

  const image = document.createElement('img');
  image.decoding = 'async';
  image.alt = '';

  const caption = document.createElement('figcaption');
  caption.className = 'lightbox__caption';
  caption.hidden = true;

  figure.appendChild(image);
  figure.appendChild(caption);

  overlay.appendChild(closeButton);
  overlay.appendChild(figure);

  document.body.appendChild(overlay);

  let visible = false;
  let currentSource = '';
  let activeTrigger = null;

  const hide = () => {
    if (!visible) {
      return;
    }

    overlay.classList.remove('is-visible');
    overlay.hidden = true;
    image.src = '';
    document.body.classList.remove('has-lightbox');
    visible = false;
    currentSource = '';

    if (activeTrigger) {
      activeTrigger.focus({ preventScroll: true });
      activeTrigger = null;
    }
  };

  const show = (source, altText, captionText, trigger) => {
    if (typeof source !== 'string' || source === '') {
      return;
    }

    if (visible && currentSource === source) {
      hide();

      return;
    }

    image.src = source;
    image.alt = altText ?? '';

    if (captionText) {
      caption.textContent = captionText;
      caption.hidden = false;
    } else {
      caption.textContent = '';
      caption.hidden = true;
    }

    overlay.hidden = false;
    overlay.classList.add('is-visible');
    document.body.classList.add('has-lightbox');
    visible = true;
    currentSource = source;
    activeTrigger = trigger ?? null;
    closeButton.focus({ preventScroll: true });
  };

  closeButton.addEventListener('click', hide);
  figure.addEventListener('click', hide);
  overlay.addEventListener('click', (event) => {
    if (event.target === overlay) {
      hide();
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && visible) {
      event.preventDefault();
      hide();
    }
  });

  return {
    show,
    hide,
    isVisible: () => visible,
    getCurrentSource: () => currentSource,
  };
}

function updateStatus() {
  if (!(statusLine instanceof HTMLElement)) {
    return;
  }

  statusLine.classList.toggle('error', Boolean(state.error));

  if (state.loading) {
    statusLine.textContent = 'Lade Rückblicke …';

    return;
  }

  if (state.error) {
    statusLine.textContent = state.error;

    return;
  }

  if (!state.meta) {
    statusLine.textContent = 'Keine Daten verfügbar.';

    return;
  }

  const delivered = numberFormatter.format(state.meta.anzahlGeliefert ?? state.items.length ?? 0);
  const total = numberFormatter.format(state.meta.gesamtVerfuegbar ?? state.items.length ?? 0);

  statusLine.textContent = `${delivered} von ${total} Rückblicken sichtbar.`;
}

function updateScoreLabel() {
  if (!(scoreInput instanceof HTMLInputElement) || !(scoreValue instanceof HTMLElement)) {
    return;
  }

  const value = Number.parseFloat(scoreInput.value);
  scoreValue.textContent = Number.isNaN(value) ? '0.00' : value.toFixed(2);
}

function populateStrategyOptions(strategies) {
  if (!(strategySelect instanceof HTMLSelectElement)) {
    return;
  }

  const currentValue = strategySelect.value;

  while (strategySelect.options.length > 1) {
    strategySelect.remove(1);
  }

  strategies.forEach((strategy) => {
    if (typeof strategy !== 'string' || strategy === '') {
      return;
    }

    const option = document.createElement('option');
    option.value = strategy;
    option.textContent = formatLabel(strategy);
    strategySelect.appendChild(option);
  });

  if (currentValue && strategies.includes(currentValue)) {
    strategySelect.value = currentValue;
  }
}

function applyFiltersFromForm() {
  state.filters.score = scoreInput instanceof HTMLInputElement ? scoreInput.value : defaultFilters.score;
  state.filters.strategie = strategySelect instanceof HTMLSelectElement ? strategySelect.value : defaultFilters.strategie;
  state.filters.datum = dateInput instanceof HTMLInputElement ? dateInput.value : defaultFilters.datum;
}

function setFormFromState() {
  if (scoreInput instanceof HTMLInputElement) {
    scoreInput.value = state.filters.score ?? defaultFilters.score;
  }

  if (strategySelect instanceof HTMLSelectElement) {
    strategySelect.value = state.filters.strategie ?? '';
  }

  if (dateInput instanceof HTMLInputElement) {
    dateInput.value = state.filters.datum ?? '';
  }
}

function formatLabel(value) {
  if (typeof value !== 'string' || value === '') {
    return '';
  }

  const friendly = value.replace(/_/g, ' ');
  return friendly.charAt(0).toUpperCase() + friendly.slice(1);
}

function formatScore(value) {
  const parsed = Number.parseFloat(value);
  if (Number.isNaN(parsed)) {
    return '0.00';
  }

  return parsed.toFixed(2);
}

function formatSeconds(value) {
  const parsed = Number.parseFloat(value);
  if (Number.isNaN(parsed)) {
    return '0.0';
  }

  return parsed.toFixed(1);
}

function buildTimeline(range) {
  if (!range || typeof range !== 'object') {
    return '';
  }

  const parts = [];
  if (typeof range.von === 'string') {
    const formatted = formatDate(range.von);
    if (formatted) {
      parts.push(formatted);
    }
  }

  if (typeof range.bis === 'string') {
    const formatted = formatDate(range.bis);
    if (formatted) {
      if (parts.length === 1) {
        return `${parts[0]} – ${formatted}`;
      }

      parts.push(formatted);
    }
  }

  if (parts.length === 2) {
    return `${parts[0]} – ${parts[1]}`;
  }

  return parts.join('');
}

function formatDate(value) {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return null;
  }

  return dateFormatter.format(date);
}

function formatDateTime(value) {
  if (typeof value !== 'string' || value === '') {
    return null;
  }

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return null;
  }

  return dateTimeFormatter.format(date);
}
