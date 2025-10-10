import { expect, test } from '@playwright/test';

const pixelPng = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/w8AAusB9Y9v0soAAAAASUVORK5CYII=',
  'base64'
);

declare global {
  interface Window {
    __timeouts?: number[];
  }
}

test.describe('Rückblick SPA', () => {
  test('lädt Feed, filtert nach Score und Strategie', async ({ page }) => {
    await page.route('**/api/feed**', async (route) => {
      const url = new URL(route.request().url());
      const score = Number.parseFloat(url.searchParams.get('score') ?? '0');
      const strategie = url.searchParams.get('strategie') ?? '';

      const baseItems = [
        {
          id: 'holiday-event',
          algorithmus: 'holiday_event',
          gruppe: 'city_and_events',
          titel: 'Winter in Berlin',
          untertitel: 'Lichterzauber an der Spree',
          score: 0.68,
          coverMediaId: 1,
          cover: '/api/media/1/thumbnail?breite=640',
          mitglieder: [1, 2, 3, 4],
          galerie: [
            {
              mediaId: 1,
              thumbnail: '/api/media/1/thumbnail?breite=320',
              lightbox: '/api/media/1/thumbnail?breite=1024',
            },
            {
              mediaId: 2,
              thumbnail: '/api/media/2/thumbnail?breite=320',
              lightbox: '/api/media/2/thumbnail?breite=1024',
            },
            {
              mediaId: 3,
              thumbnail: '/api/media/3/thumbnail?breite=320',
              lightbox: '/api/media/3/thumbnail?breite=1024',
            },
          ],
          zeitspanne: {
            von: '2024-01-03T10:00:00+01:00',
            bis: '2024-01-04T18:00:00+01:00',
          },
          zusatzdaten: {
            group: 'city_and_events',
          },
        },
        {
          id: 'mountain-tour',
          algorithmus: 'hike_adventure',
          gruppe: 'nature_and_seasons',
          titel: 'Alpenüberquerung',
          untertitel: 'Sonnenaufgang am Gipfel',
          score: 0.52,
          coverMediaId: 4,
          cover: '/api/media/4/thumbnail?breite=640',
          mitglieder: [4, 5, 6, 7],
          galerie: [
            {
              mediaId: 4,
              thumbnail: '/api/media/4/thumbnail?breite=320',
              lightbox: '/api/media/4/thumbnail?breite=1024',
            },
            {
              mediaId: 5,
              thumbnail: '/api/media/5/thumbnail?breite=320',
              lightbox: '/api/media/5/thumbnail?breite=1024',
            },
            {
              mediaId: 6,
              thumbnail: '/api/media/6/thumbnail?breite=320',
              lightbox: '/api/media/6/thumbnail?breite=1024',
            },
          ],
          zeitspanne: {
            von: '2023-08-12T05:30:00+02:00',
            bis: '2023-08-13T20:45:00+02:00',
          },
          zusatzdaten: {
            group: 'nature_and_seasons',
          },
        },
      ];

      let items = baseItems;

      if (strategie === 'holiday_event') {
        items = [baseItems[0]];
      } else if (score > 0.6) {
        items = [baseItems[0]];
      }

      const body = {
        meta: {
          erstelltAm: '2024-03-02T10:00:00+01:00',
          gesamtVerfuegbar: baseItems.length,
          anzahlGeliefert: items.length,
          verfuegbareStrategien: ['holiday_event', 'hike_adventure'],
          verfuegbareGruppen: ['city_and_events', 'nature_and_seasons'],
          filter: {
            score: score || null,
            strategie: strategie || null,
            datum: url.searchParams.get('datum'),
            limit: Number.parseInt(url.searchParams.get('limit') ?? '0', 10),
          },
        },
        items,
      };

      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(body),
      });
    });

    await page.route('**/api/media/**/thumbnail**', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'image/png',
        body: pixelPng,
      });
    });

    await page.goto('/app/');

    await expect(page.getByRole('heading', { level: 1, name: 'Rückblick-Galerie' })).toBeVisible();
    await expect(page.locator('.card')).toHaveCount(2);
    await expect(page.locator('.card').first()).toContainText('Winter in Berlin');

    await page.locator('input[name="score"]').evaluate((slider, value) => {
      slider.value = value;
      slider.dispatchEvent(new Event('change', { bubbles: true }));
    }, '0.65');

    await expect(page.locator('.card')).toHaveCount(1);
    await expect(page.locator('.status-line')).toContainText('1 von 2');

    await page.selectOption('select[name="strategie"]', 'holiday_event');
    await expect(page.locator('.card')).toHaveCount(1);
    await expect(page.locator('.card').first()).toContainText('Winter in Berlin');

    await page.getByRole('button', { name: 'Zurücksetzen' }).click();
    await expect(page.locator('.card')).toHaveCount(2);
  });

  test('startet die Videoerstellung bei Bedarf neu', async ({ page }) => {
    await page.addInitScript(() => {
      const originalSetTimeout = window.setTimeout;
      window.__timeouts = [];
      window.setTimeout = function patchedSetTimeout(handler, timeout, ...args) {
        window.__timeouts.push(typeof timeout === 'number' ? timeout : Number(timeout));

        return originalSetTimeout.call(this, handler, timeout, ...args);
      };
    });

    await page.route('**/api/feed**', async (route) => {
      const body = {
        meta: {
          erstelltAm: '2024-03-02T12:00:00+01:00',
          gesamtVerfuegbar: 1,
          anzahlGeliefert: 1,
          verfuegbareStrategien: ['holiday_event'],
        },
        items: [
          {
            id: 'skyline-trip',
            titel: 'Skyline-Tour',
            untertitel: 'Abendstimmung in der Stadt',
            score: 0.42,
            coverMediaId: 101,
            cover: '/api/media/101/thumbnail?breite=640',
            galerie: [
              {
                mediaId: 101,
                thumbnail: '/api/media/101/thumbnail?breite=320',
                lightbox: '/api/media/101/thumbnail?breite=1024',
              },
            ],
            slideshow: {
              status: 'nicht_verfuegbar',
              meldung: 'Noch kein Video vorhanden.',
              dauerProBildSekunden: 3.5,
            },
          },
        ],
      };

      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(body),
      });
    });

    await page.route('**/api/media/**/thumbnail**', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'image/png',
        body: pixelPng,
      });
    });

    let videoRequestCount = 0;
    await page.route('**/api/feed/skyline-trip/video', async (route) => {
      videoRequestCount += 1;
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'in_erstellung',
          meldung: 'Video wird erstellt …',
          dauerProBildSekunden: 3.5,
        }),
      });
    });

    await page.goto('/app/');

    const actionButton = page.getByRole('button', { name: 'Video erstellen' });
    await expect(actionButton).toBeVisible();

    const response = await Promise.all([
      page.waitForResponse('**/api/feed/skyline-trip/video'),
      actionButton.click(),
    ]).then(([videoResponse]) => videoResponse);

    await expect(page.locator('.slideshow__status')).toContainText('Video wird erstellt');
    await expect(page.locator('.slideshow__status .spinner')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Video erstellen' })).toHaveCount(0);

    const lastTimeout = await page.evaluate(() => window.__timeouts?.[window.__timeouts.length - 1] ?? null);
    expect(lastTimeout).toBe(4000);

    expect(videoRequestCount).toBe(1);
    expect(await response.json()).toMatchObject({ status: 'in_erstellung' });
  });
});
