
const servers = require("../servers-base.json");

module.exports = async (req, res) => {
  res.setHeader("Cache-Control", "public, s-maxage=60, stale-while-revalidate=60");

  const result = await Promise.all(
    servers.map(async (server) => {
      if (server.id !== 1) return server;

      try {
        const url =
          `https://samp-api.tk/server/${server.ip}:${server.port}`;

        const response = await fetch(url, {
          signal: AbortSignal.timeout(5000)
        });

        if (!response.ok) throw new Error("API unavailable");

        const data = await response.json();

        if (
          data.online !== true ||
          !Number.isInteger(data.players) ||
          data.players < 0
        ) {
          throw new Error("Invalid server status");
        }

        return {
          ...server,
          online: String(data.players),
          maxonline: String(data.maxplayers ?? server.maxonline)
        };
      } catch (error) {
        console.error("SA-MP query failed:", error.message);
        return {
          ...server,
          online: "0"
        };
      }
    })
  );

  res.status(200).json(result);
};
