import { readdir, readFile, writeFile } from "node:fs/promises";
import { createHash } from "node:crypto";
const root = new URL("../dist/public/", import.meta.url);
const assets = (await readdir(new URL("assets/", root)))
  .filter(name => /\.(js|css|woff2)$/.test(name))
  .map(name => `/assets/${name}`);
const shell = [
  "/",
  "/app-config.js",
  "/manifest.webmanifest",
  "/icons/icon-192.png",
  "/icons/icon-512.png",
  ...assets,
];
const source = await readFile(
  new URL("../client/public/sw.js", import.meta.url),
  "utf8"
);
const revision = createHash("sha256")
  .update(assets.join(",") + source)
  .digest("hex")
  .slice(0, 12);

await writeFile(
  new URL("sw.js", root),
  source
    .replace("bipolar-shell-v1", `bipolar-shell-${revision}`)
    .replace(
      "['/', '/manifest.webmanifest', '/icons/icon-192.png', '/icons/icon-512.png']",
      JSON.stringify(shell)
    )
);
console.log(
  `PWA: ${shell.length} recursos públicos; nenhuma API ou mídia em cache.`
);
