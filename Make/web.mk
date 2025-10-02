.PHONY: web-install web-build web-dev web-preview web-test web-serve

web-install: ## Installiert die Frontend-Abhängigkeiten (npm)
	@npm install

web-build: ## Baut die SPA-Assets über Vite
	@npm run build

web-preview: ## Startet den Vite-Preview-Server für das gebaute Bundle
	@npm run preview

web-dev: ## Startet den Vite-Entwicklungsserver
	@npm run dev

web-test: ## Führt die Playwright-Browsertests aus
	@npm run test:e2e

web-serve: ## Startet den PHP-HTTP-Einstieg für die Feed-API
	@php -S 0.0.0.0:8080 -t public public/index.php
