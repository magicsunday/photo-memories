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

const numberFormatter = new Intl.NumberFormat('de-DE');
const dateFormatter = new Intl.DateTimeFormat('de-DE', {
  day: '2-digit',
  month: 'short',
  year: 'numeric',
});

document.title = 'Rückblick-Galerie';

const app = document.querySelector('#app');
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

const filterForm = app.querySelector('[data-filter]');
const scoreInput = filterForm.querySelector('input[name="score"]');
const scoreValue = filterForm.querySelector('[data-score-value]');
const strategySelect = filterForm.querySelector('select[name="strategie"]');
const dateInput = filterForm.querySelector('input[name="datum"]');
const applyButton = filterForm.querySelector('[data-apply]');
const resetButton = filterForm.querySelector('[data-reset]');
const statusLine = app.querySelector('[data-status]');
const cardsContainer = app.querySelector('[data-cards]');

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

  state.items.forEach((item) => {
    cardsContainer.appendChild(createCard(item));
  });
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

  const gallery = document.createElement('div');
  gallery.className = 'gallery';

  const images = Array.isArray(item.galerie) && item.galerie.length > 0
    ? item.galerie
    : (item.cover ? [{ mediaId: item.coverMediaId, thumbnail: item.cover }] : []);

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
      const img = document.createElement('img');
      img.src = image.thumbnail;
      img.alt = `Medien-ID ${image.mediaId ?? ''}`;
      img.loading = 'lazy';
      img.decoding = 'async';
      figure.appendChild(img);
      gallery.appendChild(figure);
    });
  }

  card.appendChild(gallery);

  return card;
}

function updateStatus() {
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
  const value = Number.parseFloat(scoreInput.value);
  scoreValue.textContent = Number.isNaN(value) ? '0.00' : value.toFixed(2);
}

function populateStrategyOptions(strategies) {
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
  state.filters.score = scoreInput.value ?? defaultFilters.score;
  state.filters.strategie = strategySelect.value ?? defaultFilters.strategie;
  state.filters.datum = dateInput.value ?? defaultFilters.datum;
}

function setFormFromState() {
  scoreInput.value = state.filters.score ?? defaultFilters.score;
  strategySelect.value = state.filters.strategie ?? '';
  dateInput.value = state.filters.datum ?? '';
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
