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
  slideshowRequests: {},
  detail: {
    visible: false,
    item: null,
    loading: false,
    error: null,
    anchor: null,
  },
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

const detailRouteMatch = window.location.pathname.match(/^\/app\/galerie\/([^/]+)\/?$/);
const detailPageId = detailRouteMatch ? decodeURIComponent(detailRouteMatch[1]) : null;

let filterForm = null;
let scoreInput = null;
let scoreValue = null;
let strategySelect = null;
let dateInput = null;
let applyButton = null;
let resetButton = null;
let statusLine = null;
let cardsContainer = null;
let detailOverlay = null;
let detailDialog = null;
let detailTitle = null;
let detailSubtitle = null;
let detailBadges = null;
let detailTimeline = null;
let detailStatusMessage = null;
let detailSlideshow = null;
let detailGallery = null;
let detailPrimaryCloseButton = null;
let detailPageContext = null;

const lightbox = createLightbox();

const appContainer = document.querySelector('#app');
if (appContainer instanceof HTMLElement) {
  if (typeof detailPageId === 'string' && detailPageId !== '') {
    initialiseDetailPage(appContainer, detailPageId);
  } else {
    initialiseApp(appContainer);
  }
} else {
  console.error('Konnte die Anwendung nicht initialisieren: Container "#app" fehlt.');
}

function initialiseApp(app) {
  detailPageContext = null;

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

  initialiseDetailOverlay();
  renderDetailOverlay();
  document.addEventListener('keydown', handleDetailKeydown);

  updateStatus();
  renderItems();
  fetchFeed();
}

function initialiseDetailPage(app, itemId) {
  app.innerHTML = `
    <article class="detail-page" data-detail-page>
      <header class="detail-page__header">
        <a class="detail-page__back-link" href="/app/">← Zur Übersicht</a>
        <h1 class="detail-page__title" data-detail-title>Galerie</h1>
        <p class="detail-page__subtitle" data-detail-subtitle></p>
        <div class="detail-page__badges" data-detail-badges></div>
        <p class="detail-page__timeline" data-detail-timeline></p>
        <p class="detail-page__status" data-detail-status></p>
      </header>
      <div class="detail-page__slideshow" data-detail-slideshow></div>
      <div class="detail-page__gallery gallery" data-detail-gallery></div>
    </article>
  `;

  const page = app.querySelector('[data-detail-page]');
  const title = app.querySelector('[data-detail-title]');
  const subtitle = app.querySelector('[data-detail-subtitle]');
  const badges = app.querySelector('[data-detail-badges]');
  const timeline = app.querySelector('[data-detail-timeline]');
  const status = app.querySelector('[data-detail-status]');
  const slideshow = app.querySelector('[data-detail-slideshow]');
  const gallery = app.querySelector('[data-detail-gallery]');

  const elements = {
    container: page instanceof HTMLElement ? page : null,
    title: title instanceof HTMLElement ? title : null,
    subtitle: subtitle instanceof HTMLElement ? subtitle : null,
    badges: badges instanceof HTMLElement ? badges : null,
    timeline: timeline instanceof HTMLElement ? timeline : null,
    status: status instanceof HTMLElement ? status : null,
    slideshow: slideshow instanceof HTMLElement ? slideshow : null,
    gallery: gallery instanceof HTMLElement ? gallery : null,
  };

  detailPageContext = {
    itemId,
    elements,
    state: { loading: true, error: null, item: null },
  };

  renderDetailPage(elements, detailPageContext.state);
  void loadDetailPage(itemId, elements);
}

async function loadDetailPage(itemId, elements) {
  const result = await fetchDetailData(itemId);

  if (result.item) {
    const state = { loading: false, error: null, item: result.item };
    if (detailPageContext && detailPageContext.itemId === itemId) {
      detailPageContext.state = state;
    }

    renderDetailPage(elements, state);

    return;
  }

  const failureState = {
    loading: false,
    error: result.error ?? 'Es wurden keine Details zur Galerie gefunden.',
    item: null,
  };

  if (detailPageContext && detailPageContext.itemId === itemId) {
    detailPageContext.state = failureState;
  }

  renderDetailPage(elements, failureState);
}

function renderDetailPage(elements, detailState) {
  if (elements.container instanceof HTMLElement) {
    elements.container.classList.toggle('is-loading', Boolean(detailState.loading));
  }

  if (detailPageContext && detailPageContext.elements === elements) {
    detailPageContext.state = detailState;
  }

  const { title } = updateDetailElements(detailState, elements, {
    slideshow: { requestStateProvider: getSlideshowRequestState },
  });

  if (typeof title === 'string' && title !== '') {
    document.title = `Rückblick-Galerie – ${title}`;
  } else {
    document.title = 'Rückblick-Galerie';
  }
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

    pruneSlideshowRequests();

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

function pruneSlideshowRequests() {
  const activeIds = new Set();
  state.items.forEach((item) => {
    if (item && typeof item.id === 'string' && item.id !== '') {
      activeIds.add(item.id);
    }
  });

  Object.keys(state.slideshowRequests).forEach((key) => {
    if (!activeIds.has(key)) {
      delete state.slideshowRequests[key];
    }
  });
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
    ensureSlideshowRefreshTimer();
  } else if (slideshowRefreshTimer !== null) {
    cancelSlideshowRefreshTimer();
  }
}

function ensureSlideshowRefreshTimer(delay = 4000) {
  if (slideshowRefreshTimer !== null) {
    return;
  }

  slideshowRefreshTimer = window.setTimeout(() => {
    slideshowRefreshTimer = null;
    void refreshPendingSlideshows();
  }, delay);
}

function restartSlideshowRefreshTimer(delay = 4000) {
  cancelSlideshowRefreshTimer();
  ensureSlideshowRefreshTimer(delay);
}

function cancelSlideshowRefreshTimer() {
  if (slideshowRefreshTimer === null) {
    return;
  }

  window.clearTimeout(slideshowRefreshTimer);
  slideshowRefreshTimer = null;
}

function createCard(item) {
  const card = document.createElement('article');
  card.className = 'card';

  const header = document.createElement('div');
  header.className = 'card-header';

  const title = document.createElement('h2');
  const titleLink = document.createElement('a');
  titleLink.className = 'card-title-button';
  titleLink.rel = 'bookmark';
  const resolvedTitle = typeof item?.titel === 'string' && item.titel !== '' ? item.titel : 'Ohne Titel';
  titleLink.textContent = resolvedTitle;
  titleLink.setAttribute('aria-label', `Galerie „${resolvedTitle}“ öffnen`);

  if (item && typeof item.id === 'string' && item.id !== '') {
    titleLink.href = `/app/galerie/${encodeURIComponent(item.id)}`;
  } else {
    titleLink.href = '#';
    titleLink.setAttribute('aria-disabled', 'true');
    titleLink.addEventListener('click', (event) => {
      event.preventDefault();
    });
  }

  title.appendChild(titleLink);
  header.appendChild(title);

  const subtitle = document.createElement('p');
  subtitle.textContent = item.untertitel ?? '';
  subtitle.hidden = subtitle.textContent === '';
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
  populateGallery(gallery, images);
  card.appendChild(gallery);

  return card;
}

function populateGallery(container, images) {
  if (!(container instanceof HTMLElement)) {
    return;
  }

  container.textContent = '';
  lightbox.unregisterGroup(container);

  const galleryItems = Array.isArray(images) ? images : [];
  let appended = 0;
  const lightboxEntries = [];

  galleryItems.forEach((image, index) => {
    const figure = createGalleryFigure(image, () => ({ group: container, index }));
    if (figure instanceof HTMLElement) {
      container.appendChild(figure);
      appended += 1;

      const source = typeof figure.dataset.lightboxSource === 'string' ? figure.dataset.lightboxSource : '';
      if (source !== '') {
        lightboxEntries[index] = {
          source,
          alt: typeof figure.dataset.lightboxAlt === 'string' ? figure.dataset.lightboxAlt : '',
          caption: typeof figure.dataset.lightboxCaption === 'string' ? figure.dataset.lightboxCaption : '',
          trigger: figure,
        };
      } else {
        lightboxEntries[index] = null;
      }
    }
  });

  if (lightboxEntries.length > 0 && lightboxEntries.some((entry) => entry && typeof entry.source === 'string' && entry.source !== '')) {
    lightbox.registerGroup(container, lightboxEntries);
  }

  if (appended === 0) {
    const placeholder = document.createElement('figure');
    placeholder.className = 'empty';
    const note = document.createElement('figcaption');
    note.textContent = 'Keine Vorschau verfügbar';
    placeholder.appendChild(note);
    container.appendChild(placeholder);
  }
}

function updateDetailElements(detailState, elements, options = {}) {
  const item = detailState && typeof detailState.item === 'object' && detailState.item !== null
    ? detailState.item
    : null;

  const resolvedTitle = item && typeof item.titel === 'string' && item.titel !== '' ? item.titel : 'Galerie';

  if (elements.title instanceof HTMLElement) {
    elements.title.textContent = resolvedTitle;
  }

  if (elements.subtitle instanceof HTMLElement) {
    const subtitleText = item && typeof item.untertitel === 'string' ? item.untertitel : '';
    elements.subtitle.textContent = subtitleText;
    elements.subtitle.hidden = subtitleText === '';
  }

  if (elements.badges instanceof HTMLElement) {
    elements.badges.textContent = '';
    const badges = [];

    if (item && item.gruppe) {
      badges.push(formatLabel(item.gruppe));
    }

    if (item && (item.algorithmusLabel || item.algorithmus)) {
      badges.push(item.algorithmusLabel ?? formatLabel(item.algorithmus));
    }

    if (item && typeof item.score === 'number') {
      badges.push(`Score ${formatScore(item.score)}`);
    }

    badges.forEach((label) => {
      const badge = document.createElement('span');
      badge.textContent = label;
      elements.badges.appendChild(badge);
    });

    elements.badges.hidden = elements.badges.childElementCount === 0;
  }

  if (elements.timeline instanceof HTMLElement) {
    const timelineText = item ? buildTimeline(item.zeitspanne) : null;
    if (timelineText) {
      elements.timeline.textContent = `Zeitraum: ${timelineText}`;
      elements.timeline.hidden = false;
    } else {
      elements.timeline.textContent = '';
      elements.timeline.hidden = true;
    }
  }

  if (elements.status instanceof HTMLElement) {
    if (detailState && detailState.loading) {
      elements.status.textContent = 'Galerie wird geladen …';
      elements.status.classList.remove('is-error');
      elements.status.hidden = false;
    } else if (detailState && typeof detailState.error === 'string' && detailState.error !== '') {
      elements.status.textContent = detailState.error;
      elements.status.classList.add('is-error');
      elements.status.hidden = false;
    } else {
      elements.status.textContent = '';
      elements.status.classList.remove('is-error');
      elements.status.hidden = true;
    }
  }

  if (elements.slideshow instanceof HTMLElement) {
    const slideshowOptions = options && typeof options === 'object' ? options.slideshow ?? null : null;
    const requestStateProvider = slideshowOptions && typeof slideshowOptions.requestStateProvider === 'function'
      ? slideshowOptions.requestStateProvider
      : null;

    elements.slideshow.textContent = '';

    if (item) {
      const slideshowSection = createSlideshowSection(item, {
        requestStateProvider,
      });

      if (slideshowSection) {
        elements.slideshow.appendChild(slideshowSection);
        elements.slideshow.hidden = false;
      } else {
        elements.slideshow.hidden = true;
      }
    } else {
      elements.slideshow.hidden = true;
    }
  }

  if (elements.gallery instanceof HTMLElement) {
    if (item) {
      const galleryItems = Array.isArray(item.galerie) ? item.galerie : [];
      populateGallery(elements.gallery, galleryItems);
    } else {
      elements.gallery.textContent = '';
    }
  }

  return { title: resolvedTitle };
}

function createGalleryFigure(image, contextProvider) {
  if (!image || typeof image.thumbnail !== 'string' || image.thumbnail === '') {
    return null;
  }

  const figure = document.createElement('figure');
  figure.classList.add('is-interactive');
  figure.tabIndex = 0;
  figure.setAttribute('role', 'button');

  const img = document.createElement('img');
  img.src = image.thumbnail;
  const detailSource = typeof image.lightbox === 'string' && image.lightbox !== ''
    ? image.lightbox
    : image.thumbnail;
  const takenAtLabel = formatDateTime(image.aufgenommenAm);
  const mediaIdLabel = typeof image.mediaId === 'number'
    ? `Medien-ID ${image.mediaId}`
    : 'Medienvorschau';
  img.alt = takenAtLabel ? `${mediaIdLabel}, aufgenommen am ${takenAtLabel}` : mediaIdLabel;
  img.loading = 'lazy';
  img.decoding = 'async';
  if (takenAtLabel) {
    img.title = `Aufgenommen am ${takenAtLabel}`;
  }
  figure.appendChild(img);

  const captionText = takenAtLabel ? `Aufgenommen am ${takenAtLabel}` : '';
  figure.setAttribute('aria-label', captionText !== '' ? captionText : img.alt);
  figure.dataset.lightboxSource = detailSource;
  figure.dataset.lightboxAlt = img.alt ?? '';
  figure.dataset.lightboxCaption = captionText;

  if (captionText !== '') {
    const caption = document.createElement('figcaption');
    caption.textContent = captionText;
    figure.appendChild(caption);
  }

  const activateLightbox = () => {
    const context = typeof contextProvider === 'function'
      ? contextProvider()
      : null;

    lightbox.show(detailSource, img.alt, captionText, figure, context);
  };

  figure.addEventListener('click', activateLightbox);
  figure.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      activateLightbox();
    }
  });

  return figure;
}

function openDetail(item, triggerElement) {
  if (!item || typeof item.id !== 'string' || item.id === '') {
    return;
  }

  const gallery = Array.isArray(item.galerie) ? item.galerie.slice() : [];
  state.detail.visible = true;
  state.detail.loading = true;
  state.detail.error = null;
  state.detail.anchor = triggerElement instanceof HTMLElement ? triggerElement : null;
  state.detail.item = { ...item, galerie: gallery };

  renderDetailOverlay();

  window.setTimeout(() => {
    focusDetailOverlay();
  }, 0);

  void fetchDetail(item.id);
}

async function fetchDetailData(itemId) {
  const params = buildDetailQueryParams();
  const query = params.toString();
  const url = query === ''
    ? `/api/feed/${encodeURIComponent(itemId)}`
    : `/api/feed/${encodeURIComponent(itemId)}?${query}`;

  try {
    const response = await fetch(url, {
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
      } catch (parseError) {
        console.warn('Antwort konnte nicht als JSON gelesen werden', parseError);
      }

      throw new Error(message);
    }

    const payload = await response.json();
    if (payload && typeof payload.item === 'object' && payload.item !== null) {
      return { item: payload.item, error: null };
    }

    return { item: null, error: 'Es wurden keine Details zur Galerie gefunden.' };
  } catch (error) {
    const message = error instanceof Error
      ? error.message
      : 'Unbekannter Fehler beim Laden der Galerie.';

    return { item: null, error: message };
  }
}

async function fetchDetail(itemId) {
  state.detail.loading = true;
  state.detail.error = null;
  renderDetailOverlay();

  const targetId = itemId;
  const result = await fetchDetailData(targetId);

  if (!state.detail.visible || !state.detail.item || state.detail.item.id !== targetId) {
    state.detail.loading = false;
    return;
  }

  state.detail.loading = false;

  if (result.item) {
    state.detail.item = result.item;
    state.detail.error = null;
  } else {
    state.detail.error = result.error ?? 'Es wurden keine Details zur Galerie gefunden.';
  }

  renderDetailOverlay();
}

function buildDetailQueryParams() {
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

  params.set('felder', 'basis,zeit,galerie,slideshow');
  params.set('metaFelder', 'basis');

  return params;
}

function closeDetail() {
  const anchor = state.detail.anchor instanceof HTMLElement ? state.detail.anchor : null;

  state.detail.visible = false;
  state.detail.loading = false;
  state.detail.error = null;
  state.detail.item = null;
  state.detail.anchor = null;

  renderDetailOverlay();

  if (anchor) {
    anchor.focus();
  }
}

function renderDetailOverlay() {
  if (!(detailOverlay instanceof HTMLElement)) {
    return;
  }

  if (!state.detail.visible) {
    detailOverlay.classList.remove('is-visible');
    detailOverlay.setAttribute('aria-hidden', 'true');
    detailOverlay.setAttribute('hidden', '');
    document.body.classList.remove('has-detail-overlay');

    if (detailStatusMessage instanceof HTMLElement) {
      detailStatusMessage.textContent = '';
      detailStatusMessage.classList.remove('is-error');
      detailStatusMessage.hidden = true;
    }

    if (detailSlideshow instanceof HTMLElement) {
      detailSlideshow.textContent = '';
      detailSlideshow.hidden = true;
    }

    if (detailGallery instanceof HTMLElement) {
      detailGallery.textContent = '';
    }

    return;
  }

  detailOverlay.removeAttribute('hidden');
  detailOverlay.setAttribute('aria-hidden', 'false');
  detailOverlay.classList.add('is-visible');
  document.body.classList.add('has-detail-overlay');

  updateDetailElements({
    item: state.detail.item,
    loading: state.detail.loading,
    error: state.detail.error,
  }, {
    title: detailTitle,
    subtitle: detailSubtitle,
    badges: detailBadges,
    timeline: detailTimeline,
    status: detailStatusMessage,
    slideshow: detailSlideshow,
    gallery: detailGallery,
  }, {
    slideshow: { requestStateProvider: getSlideshowRequestState },
  });
}

function initialiseDetailOverlay() {
  if (detailOverlay instanceof HTMLElement) {
    return;
  }

  const overlay = document.createElement('section');
  overlay.className = 'detail-overlay';
  overlay.setAttribute('aria-hidden', 'true');
  overlay.setAttribute('hidden', '');

  overlay.innerHTML = `
    <div class="detail-overlay__backdrop" data-detail-close></div>
    <div class="detail-overlay__dialog" data-detail-dialog role="dialog" aria-modal="true" aria-labelledby="detail-overlay-title">
      <header class="detail-overlay__header">
        <div class="detail-overlay__headline">
          <h2 id="detail-overlay-title" data-detail-title>Galerie</h2>
          <p class="detail-overlay__subtitle" data-detail-subtitle></p>
        </div>
        <button type="button" class="detail-overlay__close" data-detail-close data-detail-initial-focus aria-label="Schließen">
          Schließen
        </button>
      </header>
      <div class="detail-overlay__badges" data-detail-badges></div>
      <p class="detail-overlay__timeline" data-detail-timeline></p>
      <p class="detail-overlay__status" data-detail-status></p>
      <div class="detail-overlay__slideshow" data-detail-slideshow></div>
      <div class="detail-overlay__gallery gallery" data-detail-gallery></div>
    </div>
  `;

  document.body.appendChild(overlay);

  detailOverlay = overlay;
  detailDialog = overlay.querySelector('[data-detail-dialog]');
  detailTitle = overlay.querySelector('[data-detail-title]');
  detailSubtitle = overlay.querySelector('[data-detail-subtitle]');
  detailBadges = overlay.querySelector('[data-detail-badges]');
  detailTimeline = overlay.querySelector('[data-detail-timeline]');
  detailStatusMessage = overlay.querySelector('[data-detail-status]');
  detailSlideshow = overlay.querySelector('[data-detail-slideshow]');
  detailGallery = overlay.querySelector('[data-detail-gallery]');
  detailPrimaryCloseButton = overlay.querySelector('[data-detail-initial-focus]');

  overlay.querySelectorAll('[data-detail-close]').forEach((element) => {
    element.addEventListener('click', () => {
      closeDetail();
    });
  });
}

function focusDetailOverlay() {
  if (!state.detail.visible) {
    return;
  }

  if (detailPrimaryCloseButton instanceof HTMLElement) {
    detailPrimaryCloseButton.focus();

    return;
  }

  if (detailDialog instanceof HTMLElement) {
    const fallback = detailDialog.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
    if (fallback instanceof HTMLElement) {
      fallback.focus();
    }
  }
}

function handleDetailKeydown(event) {
  if (!state.detail.visible || !(detailOverlay instanceof HTMLElement)) {
    return;
  }

  if (event.key === 'Escape') {
    event.preventDefault();
    closeDetail();

    return;
  }

  if (event.key === 'Tab') {
    const focusable = Array.from(
      detailOverlay.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'),
    ).filter((element) => element instanceof HTMLElement && !element.hasAttribute('disabled') && element.closest('[hidden]') === null);

    if (focusable.length === 0) {
      event.preventDefault();

      return;
    }

    const first = focusable[0];
    const last = focusable[focusable.length - 1];

    if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    } else if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    }
  }
}

function normaliseSlideshowRequestState(candidate) {
  if (!candidate || typeof candidate !== 'object') {
    return { loading: false, error: null };
  }

  const loading = candidate.loading === true;
  const error = typeof candidate.error === 'string' && candidate.error !== '' ? candidate.error : null;

  return { loading, error };
}

function createSlideshowSection(item, options = {}) {
  if (!item || typeof item !== 'object') {
    return null;
  }

  const data = item.slideshow;
  if (!data || typeof data.status !== 'string') {
    return null;
  }

  const itemId = typeof item.id === 'string' && item.id !== '' ? item.id : null;
  const requestStateProvider = options && typeof options.requestStateProvider === 'function'
    ? options.requestStateProvider
    : null;
  const requestStateCandidate = itemId
    ? (requestStateProvider ? requestStateProvider(itemId) : state.slideshowRequests[itemId])
    : null;
  const requestState = normaliseSlideshowRequestState(requestStateCandidate);

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
  status.setAttribute('role', 'status');

  const showSpinner = requestState.loading || data.status === 'in_erstellung';

  if (requestState.error) {
    status.classList.add('is-error');
    status.textContent = requestState.error;
  } else if (requestState.loading) {
    status.classList.add('is-loading');
    status.textContent = 'Video-Anfrage wird gesendet …';
  } else if (data.status === 'in_erstellung') {
    status.textContent = 'Video wird erstellt …';
  } else if (data.status === 'fehlgeschlagen') {
    status.classList.add('is-error');
    status.textContent = typeof data.meldung === 'string' && data.meldung !== ''
      ? data.meldung
      : 'Video konnte nicht erstellt werden.';
  } else {
    status.textContent = typeof data.meldung === 'string' && data.meldung !== ''
      ? data.meldung
      : 'Kein Video verfügbar.';
  }

  if (showSpinner) {
    const spinner = createSpinner();
    status.prepend(spinner);
  }

  container.appendChild(status);

  if (itemId && (data.status === 'nicht_verfuegbar' || data.status === 'fehlgeschlagen')) {
    const actions = document.createElement('div');
    actions.className = 'slideshow__actions';

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'slideshow__button';
    button.textContent = data.status === 'fehlgeschlagen' ? 'Erneut erstellen' : 'Video erstellen';
    button.disabled = requestState.loading;
    button.addEventListener('click', () => {
      if (!requestState.loading) {
        void triggerSlideshowGeneration(itemId);
      }
    });

    actions.appendChild(button);
    container.appendChild(actions);
  }

  return container;
}

function createSpinner() {
  const spinner = document.createElement('span');
  spinner.className = 'spinner';
  spinner.setAttribute('aria-hidden', 'true');

  return spinner;
}

function getSlideshowRequestState(itemId) {
  if (typeof itemId !== 'string' || itemId === '') {
    return { loading: false, error: null };
  }

  const candidate = state.slideshowRequests[itemId] ?? null;

  return normaliseSlideshowRequestState(candidate);
}

function updateSlideshowRequestViews(itemId) {
  if (typeof itemId !== 'string' || itemId === '') {
    return;
  }

  if (state.detail.visible && state.detail.item && state.detail.item.id === itemId) {
    renderDetailOverlay();
  }

  if (detailPageContext && detailPageContext.itemId === itemId && detailPageContext.elements && detailPageContext.state) {
    renderDetailPage(detailPageContext.elements, detailPageContext.state);
  }
}

function notifySlideshowUpdate(itemId, itemPayload, slideshow) {
  if (typeof itemId !== 'string' || itemId === '') {
    return;
  }

  const payloadObject = itemPayload && typeof itemPayload === 'object' ? itemPayload : null;
  const slideshowData = slideshow && typeof slideshow === 'object' ? slideshow : null;
  const hasPayloadSlideshow = payloadObject ? Object.prototype.hasOwnProperty.call(payloadObject, 'slideshow') : false;
  const payloadSlideshow = hasPayloadSlideshow ? payloadObject.slideshow : undefined;

  if (state.detail.visible && state.detail.item && state.detail.item.id === itemId) {
    const updatedItem = { ...state.detail.item };

    if (payloadObject) {
      Object.assign(updatedItem, payloadObject);
    }

    if (slideshowData) {
      updatedItem.slideshow = slideshowData;
    } else if (hasPayloadSlideshow) {
      updatedItem.slideshow = payloadSlideshow;
    }

    state.detail.item = updatedItem;
    renderDetailOverlay();
  }

  if (detailPageContext && detailPageContext.itemId === itemId && detailPageContext.state && detailPageContext.elements) {
    const currentState = detailPageContext.state;
    if (currentState.item) {
      const updatedDetailItem = { ...currentState.item };

      if (payloadObject) {
        Object.assign(updatedDetailItem, payloadObject);
      }

      if (slideshowData) {
        updatedDetailItem.slideshow = slideshowData;
      } else if (hasPayloadSlideshow) {
        updatedDetailItem.slideshow = payloadSlideshow;
      }

      const nextState = { ...currentState, item: updatedDetailItem };
      detailPageContext.state = nextState;
      renderDetailPage(detailPageContext.elements, nextState);
    }
  }
}

async function triggerSlideshowGeneration(itemId) {
  if (typeof itemId !== 'string' || itemId === '') {
    return null;
  }

  state.slideshowRequests[itemId] = { loading: true, error: null };
  renderItems();
  updateSlideshowRequestViews(itemId);

  try {
    const response = await fetch(`/api/feed/${encodeURIComponent(itemId)}/video`, {
      method: 'POST',
      headers: { Accept: 'application/json' },
    });

    if (!response.ok) {
      let message = `Fehler ${response.status}`;
      try {
        const body = await response.json();
        const errorMessage = body && typeof body.error === 'string' && body.error !== '' ? body.error : null;
        if (errorMessage) {
          message += `: ${errorMessage}`;
        }
      } catch (parseError) {
        console.warn('Antwort konnte nicht als JSON gelesen werden', parseError);
      }

      throw new Error(message);
    }

    const payload = await response.json();
    const { slideshow, item } = normaliseSlideshowTriggerPayload(payload);

    if (!slideshow || typeof slideshow.status !== 'string') {
      throw new Error('Ungültige Antwort vom Server erhalten.');
    }

    applySlideshowUpdate(itemId, item, slideshow);

    state.slideshowRequests[itemId] = { loading: false, error: null };
    renderItems();
    updateSlideshowRequestViews(itemId);
    notifySlideshowUpdate(itemId, item, slideshow);

    if (slideshow.status === 'in_erstellung') {
      restartSlideshowRefreshTimer(4000);
    } else if (slideshow.status === 'bereit') {
      cancelSlideshowRefreshTimer();
    }

    return slideshow;
  } catch (error) {
    console.error('Video-Erstellung konnte nicht gestartet werden', error);
    const message = error instanceof Error ? error.message : 'Unbekannter Fehler bei der Videoerstellung.';
    state.slideshowRequests[itemId] = { loading: false, error: message };
    renderItems();
    updateSlideshowRequestViews(itemId);

    return null;
  }
}

function normaliseSlideshowTriggerPayload(payload) {
  if (!payload || typeof payload !== 'object') {
    return { slideshow: null, item: null };
  }

  if (payload.item && typeof payload.item === 'object') {
    const item = payload.item;
    const slideshow = item && typeof item === 'object' ? item.slideshow ?? null : null;

    return { slideshow, item };
  }

  if (payload.slideshow && typeof payload.slideshow === 'object') {
    return { slideshow: payload.slideshow, item: null };
  }

  if (typeof payload.status === 'string') {
    return { slideshow: payload, item: null };
  }

  return { slideshow: null, item: null };
}

function applySlideshowUpdate(itemId, itemPayload, slideshow) {
  let updated = false;

  if (itemPayload && typeof itemPayload === 'object' && typeof itemPayload.id === 'string') {
    const index = state.items.findIndex((entry) => entry && entry.id === itemPayload.id);
    if (index !== -1) {
      state.items[index] = { ...state.items[index], ...itemPayload };
      updated = true;
    }
  }

  if (!updated) {
    const index = state.items.findIndex((entry) => entry && entry.id === itemId);
    if (index !== -1) {
      state.items[index] = { ...state.items[index], slideshow };
      updated = true;
    }
  }

  if (!updated) {
    console.warn('Konnte Slideshow-Update nicht anwenden: Element nicht im Feed gefunden.', {
      itemId,
      itemPayload,
    });
  }

  return updated;
}

async function refreshPendingSlideshows() {
  const pendingIds = getPendingSlideshowItemIds();
  if (pendingIds.length === 0) {
    cancelSlideshowRefreshTimer();

    return;
  }

  const results = await Promise.all(
    pendingIds.map((pendingId) => fetchSlideshowStatus(pendingId)),
  );

  const hasUpdates = results.some((result) => result.updated);

  if (hasUpdates) {
    renderItems();
  }

  const pendingAfterUpdate = getPendingSlideshowItemIds();
  if (pendingAfterUpdate.length > 0) {
    ensureSlideshowRefreshTimer();
  } else {
    cancelSlideshowRefreshTimer();
  }
}

function getPendingSlideshowItemIds() {
  return state.items
    .filter((item) => item && typeof item === 'object')
    .filter((item) => item.slideshow && item.slideshow.status === 'in_erstellung')
    .filter((item) => typeof item.id === 'string' && item.id !== '')
    .map((item) => item.id);
}

async function fetchSlideshowStatus(itemId) {
  try {
    const response = await fetch(`/api/feed/${encodeURIComponent(itemId)}/video`, {
      method: 'POST',
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    });

    if (!response.ok) {
      return { slideshow: null, updated: false };
    }

    const payload = await response.json();
    const { slideshow, item } = normaliseSlideshowTriggerPayload(payload);

    if (!slideshow) {
      return { slideshow: null, updated: false };
    }

    const updated = applySlideshowUpdate(itemId, item, slideshow);
    notifySlideshowUpdate(itemId, item, slideshow);

    return { slideshow, updated };
  } catch (error) {
    console.error('Konnte Videostatus nicht abrufen.', error);

    return { slideshow: null, updated: false };
  }
}

function createLightbox() {
  const overlay = document.createElement('div');
  overlay.className = 'lightbox';
  overlay.hidden = true;
  overlay.setAttribute('role', 'dialog');
  overlay.setAttribute('aria-modal', 'true');
  overlay.setAttribute('aria-label', 'Bildvorschau');

  const previousButton = document.createElement('button');
  previousButton.type = 'button';
  previousButton.className = 'lightbox__nav lightbox__nav--previous';
  previousButton.setAttribute('aria-label', 'Vorheriges Bild');
  previousButton.textContent = '‹';

  const nextButton = document.createElement('button');
  nextButton.type = 'button';
  nextButton.className = 'lightbox__nav lightbox__nav--next';
  nextButton.setAttribute('aria-label', 'Nächstes Bild');
  nextButton.textContent = '›';

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

  overlay.appendChild(previousButton);
  overlay.appendChild(nextButton);
  overlay.appendChild(closeButton);
  overlay.appendChild(figure);

  document.body.appendChild(overlay);

  let visible = false;
  let currentSource = '';
  let currentGroup = null;
  let currentIndex = -1;
  let activeTrigger = null;
  const groups = new Map();

  const normaliseCaption = (text) => (typeof text === 'string' ? text : '');

  const resolveAvailableEntries = (group) => {
    if (!(group instanceof HTMLElement)) {
      return 0;
    }

    const registeredEntries = groups.get(group);
    if (!Array.isArray(registeredEntries)) {
      return 0;
    }

    return registeredEntries.reduce((count, entry) => (entry ? count + 1 : count), 0);
  };

  const updateNavigationControls = () => {
    const hasGroup = currentGroup instanceof HTMLElement;
    if (!hasGroup) {
      previousButton.hidden = true;
      previousButton.disabled = true;
      nextButton.hidden = true;
      nextButton.disabled = true;

      return;
    }

    const availableEntries = resolveAvailableEntries(currentGroup);
    const navigable = availableEntries > 1;

    previousButton.hidden = !navigable;
    nextButton.hidden = !navigable;
    previousButton.disabled = !navigable;
    nextButton.disabled = !navigable;
  };

  const renderEntry = (entry, group, index, triggerOverride) => {
    if (!entry || typeof entry.source !== 'string' || entry.source === '') {
      return;
    }

    const isGrouped = group instanceof HTMLElement;

    if (visible) {
      if (isGrouped && currentGroup === group && currentIndex === index) {
        return;
      }

      if (!isGrouped && currentSource === entry.source) {
        hide();

        return;
      }
    }

    image.src = entry.source;
    image.alt = entry.alt ?? '';

    const captionText = normaliseCaption(entry.caption);
    if (captionText !== '') {
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
    currentSource = entry.source;
    currentGroup = isGrouped ? group : null;
    currentIndex = isGrouped ? index : -1;

    if (triggerOverride instanceof HTMLElement) {
      activeTrigger = triggerOverride;
    } else if (entry.trigger instanceof HTMLElement) {
      activeTrigger = entry.trigger;
    }

    closeButton.focus({ preventScroll: true });
    updateNavigationControls();
  };

  const registerGroup = (group, entries) => {
    if (!(group instanceof HTMLElement)) {
      return;
    }

    const normalised = Array.isArray(entries)
      ? entries.map((entry) => {
        if (!entry || typeof entry.source !== 'string' || entry.source === '') {
          return null;
        }

        return {
          source: entry.source,
          alt: entry.alt ?? '',
          caption: normaliseCaption(entry.caption),
          trigger: entry.trigger instanceof HTMLElement ? entry.trigger : null,
        };
      })
      : [];

    const hasValidEntry = normalised.some((entry) => entry !== null);
    if (!hasValidEntry) {
      groups.delete(group);

      if (currentGroup === group) {
        currentGroup = null;
        currentIndex = -1;
      }

      return;
    }

    groups.set(group, normalised);

    if (group === currentGroup) {
      updateNavigationControls();
    }
  };

  const unregisterGroup = (group) => {
    if (!(group instanceof HTMLElement)) {
      return;
    }

    groups.delete(group);

    if (currentGroup === group) {
      currentGroup = null;
      currentIndex = -1;
      updateNavigationControls();
    }
  };

  const resolveGroupIndex = (entries, index, direction) => {
    if (!Array.isArray(entries) || entries.length === 0) {
      return null;
    }

    const size = entries.length;
    let candidate = ((index % size) + size) % size;

    if (entries[candidate]) {
      return candidate;
    }

    if (direction === 0) {
      let forward = (candidate + 1) % size;
      while (forward !== candidate) {
        if (entries[forward]) {
          return forward;
        }

        forward = (forward + 1) % size;
      }

      return null;
    }

    const step = direction > 0 ? 1 : -1;
    let pointer = candidate;

    for (let i = 0; i < size; i += 1) {
      pointer = (pointer + step + size) % size;
      if (entries[pointer]) {
        return pointer;
      }
    }

    return null;
  };

  const showGroupEntry = (group, index, direction = 0) => {
    if (!(group instanceof HTMLElement)) {
      return;
    }

    const entries = groups.get(group);
    if (!entries || entries.length === 0) {
      return;
    }

    const targetIndex = resolveGroupIndex(entries, index, direction);
    if (targetIndex === null) {
      return;
    }

    const entry = entries[targetIndex];
    if (!entry) {
      return;
    }

    renderEntry(entry, group, targetIndex, entry.trigger ?? activeTrigger);
  };

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
    currentGroup = null;
    currentIndex = -1;
    updateNavigationControls();

    if (activeTrigger) {
      activeTrigger.focus({ preventScroll: true });
      activeTrigger = null;
    }
  };

  const navigateGroup = (direction) => {
    const group = currentGroup;
    if (!(group instanceof HTMLElement)) {
      return;
    }

    const delta = direction > 0 ? 1 : -1;
    const startIndex = currentIndex === -1 ? (delta > 0 ? 0 : -1) : currentIndex + delta;
    showGroupEntry(group, startIndex, delta);
  };

  const show = (source, altText, captionText, trigger, context) => {
    if (typeof source !== 'string' || source === '') {
      return;
    }

    const group = context && context.group instanceof HTMLElement ? context.group : null;
    const index = context && typeof context.index === 'number' ? context.index : -1;

    if (group) {
      if (groups.has(group)) {
        showGroupEntry(group, index, 0);

        return;
      }

      renderEntry({
        source,
        alt: altText ?? '',
        caption: captionText ?? '',
        trigger: trigger ?? null,
      }, group, index, trigger ?? null);

      return;
    }

    renderEntry({
      source,
      alt: altText ?? '',
      caption: captionText ?? '',
      trigger: trigger ?? null,
    }, null, -1, trigger ?? null);
  };

  closeButton.addEventListener('click', hide);
  figure.addEventListener('click', hide);
  overlay.addEventListener('click', (event) => {
    if (event.target === overlay) {
      hide();
    }
  });

  previousButton.addEventListener('click', () => {
    navigateGroup(-1);
  });

  nextButton.addEventListener('click', () => {
    navigateGroup(1);
  });

  document.addEventListener('keydown', (event) => {
    if (!visible) {
      return;
    }

    if (event.key === 'Escape') {
      event.preventDefault();
      hide();

      return;
    }

    if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') {
      event.preventDefault();
      navigateGroup(event.key === 'ArrowRight' ? 1 : -1);
    }
  });

  return {
    show,
    hide,
    isVisible: () => visible,
    getCurrentSource: () => currentSource,
    registerGroup,
    unregisterGroup,
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
