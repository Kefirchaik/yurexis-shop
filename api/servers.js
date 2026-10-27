const servers = require('../servers-base.json');
const online = require('../online.json');

module.exports = (req, res) => {
  res.setHeader('Cache-Control', 'public, s-maxage=60');
  res.status(200).json(servers.map(server => ({
    ...server,
    ...(online[String(server.id)] || {})
  })));
};
