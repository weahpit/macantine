import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;
import 'package:intl/intl.dart';

const _storage = FlutterSecureStorage();
const _green = Color(0xFF395C4C);

void main() => runApp(const CantineApp());

class CantineApp extends StatelessWidget {
  const CantineApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Ma cantine',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(
        useMaterial3: true,
        colorScheme: ColorScheme.fromSeed(seedColor: _green),
        scaffoldBackgroundColor: const Color(0xFFF4F6F2),
      ),
      home: const FirstScreen(),
    );
  }
}

class ApiException implements Exception {
  ApiException(this.message);
  final String message;
  @override
  String toString() => message;
}

class Api {
  static String base = '';
  static String? token;
  static String? employeeName;

  static Uri url(String path, [Map<String, String>? query]) {
    final clean = base.endsWith('/') ? base.substring(0, base.length - 1) : base;
    return Uri.parse('$clean/api/mobile$path').replace(queryParameters: query);
  }

  static Map<String, String> get _headers => {
        'Content-Type': 'application/json',
        if (token != null) 'Authorization': 'Bearer $token',
      };

  static Future<Map<String, dynamic>> _decode(http.Response r) async {
    Map<String, dynamic> body = {};
    try {
      body = jsonDecode(r.body) as Map<String, dynamic>;
    } catch (_) {}
    if (r.statusCode == 401) {
      token = null;
      await _storage.delete(key: 'token');
      throw ApiException(body['erreur']?.toString() ?? 'Session expirée. Reconnectez-vous.');
    }
    if (r.statusCode < 200 || r.statusCode >= 300) {
      throw ApiException(body['erreur']?.toString() ?? 'Erreur serveur (${r.statusCode}).');
    }
    return body;
  }

  static Future<void> login(String email, String password) async {
    final r = await http
        .post(url('/connexion'),
            headers: {'Content-Type': 'application/json'},
            body: jsonEncode({'email': email, 'mot_de_passe': password}))
        .timeout(const Duration(seconds: 20));
    final body = await _decode(r);
    token = body['jeton'] as String;
    employeeName = body['employe']?['nom']?.toString();
    await _storage.write(key: 'token', value: token);
    await _storage.write(key: 'base', value: base);
  }

  static Future<Map<String, dynamic>> tickets() async {
    Map<String, dynamic> first = {};
    final all = <dynamic>[];
    int page = 1;
    int total = 0;
    do {
      final r = await http.get(url('/tickets', {'page': '$page'}), headers: _headers).timeout(const Duration(seconds: 20));
      final body = await _decode(r);
      if (page == 1) { first = body; total = (body['total'] as num).toInt(); }
      final chunk = body['tickets'] as List;
      all.addAll(chunk);
      if (chunk.isEmpty) break;
      page++;
    } while (all.length < total);
    first['tickets'] = all;
    return first;
  }

  static Map<String, String> get imageHeaders =>
      token == null ? {} : {'Authorization': 'Bearer $token'};

  static Future<void> logout() async {
    try {
      await http.post(url('/deconnexion'), headers: _headers).timeout(const Duration(seconds: 8));
    } catch (_) {}
    token = null;
    employeeName = null;
    PaintingBinding.instance.imageCache.clear();
    PaintingBinding.instance.imageCache.clearLiveImages();
    await _storage.delete(key: 'token');
  }
}

class FirstScreen extends StatefulWidget {
  const FirstScreen({super.key});

  @override
  State<FirstScreen> createState() => _FirstScreenState();
}

class _FirstScreenState extends State<FirstScreen> {
  bool loading = true;

  @override
  void initState() {
    super.initState();
    _restore();
  }

  Future<void> _restore() async {
    final savedBase = await _storage.read(key: 'base');
    final savedToken = await _storage.read(key: 'token');
    if (savedBase != null) Api.base = savedBase;
    if (savedToken != null) Api.token = savedToken;
    if (!mounted) return;
    setState(() => loading = false);
    if (Api.token != null && Api.base.isNotEmpty) {
      Navigator.of(context).pushReplacement(MaterialPageRoute(builder: (_) => const TicketsPage()));
    }
  }

  @override
  Widget build(BuildContext context) {
    if (loading) return const Scaffold(body: Center(child: CircularProgressIndicator()));
    return LoginPage(initialBase: Api.base);
  }
}

class LoginPage extends StatefulWidget {
  const LoginPage({super.key, this.initialBase = ''});
  final String initialBase;

  @override
  State<LoginPage> createState() => _LoginPageState();
}

class _LoginPageState extends State<LoginPage> {
  late final TextEditingController server = TextEditingController(text: widget.initialBase);
  final email = TextEditingController();
  final password = TextEditingController();
  String? error;
  bool busy = false;
  bool obscure = true;

  @override
  void dispose() {
    server.dispose();
    email.dispose();
    password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final base = server.text.trim();
    final address = Uri.tryParse(base);
    final local = address != null && (address.host == '127.0.0.1' || address.host == '10.0.2.2');
    if (address == null || address.host.isEmpty || address.userInfo.isNotEmpty || address.hasQuery || address.hasFragment || (address.scheme != 'https' && !(address.scheme == 'http' && local))) {
      setState(() => error = 'Adresse HTTPS valide attendue (HTTP local autorisé).');
      return;
    }
    setState(() {
      busy = true;
      error = null;
    });
    Api.base = base;
    try {
      await Api.login(email.text.trim(), password.text);
      if (!mounted) return;
      Navigator.of(context).pushReplacement(MaterialPageRoute(builder: (_) => const TicketsPage()));
    } catch (e) {
      if (mounted) setState(() => error = e is ApiException ? e.message : 'Connexion impossible : vérifiez le réseau et l’adresse.');
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 460),
              child: Card(
                child: Padding(
                  padding: const EdgeInsets.all(22),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      const Icon(Icons.restaurant, size: 48, color: _green),
                      const SizedBox(height: 12),
                      Text('Mes tickets cantine', style: Theme.of(context).textTheme.headlineSmall, textAlign: TextAlign.center),
                      const SizedBox(height: 18),
                      TextField(
                        controller: server,
                        keyboardType: TextInputType.url,
                        autocorrect: false,
                        decoration: const InputDecoration(labelText: 'Adresse du serveur', hintText: 'https://cantine.exemple.ci'),
                      ),
                      const SizedBox(height: 12),
                      TextField(
                        controller: email,
                        keyboardType: TextInputType.emailAddress,
                        autocorrect: false,
                        decoration: const InputDecoration(labelText: 'Adresse e-mail'),
                      ),
                      const SizedBox(height: 12),
                      TextField(
                        controller: password,
                        obscureText: obscure,
                        decoration: InputDecoration(
                          labelText: 'Mot de passe',
                          suffixIcon: IconButton(
                            onPressed: () => setState(() => obscure = !obscure),
                            icon: Icon(obscure ? Icons.visibility : Icons.visibility_off),
                          ),
                        ),
                        onSubmitted: (_) => busy ? null : _submit(),
                      ),
                      if (error != null) ...[
                        const SizedBox(height: 14),
                        Text(error!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
                      ],
                      const SizedBox(height: 20),
                      FilledButton(
                        onPressed: busy ? null : _submit,
                        child: Padding(
                          padding: const EdgeInsets.symmetric(vertical: 12),
                          child: busy ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2)) : const Text('Se connecter'),
                        ),
                      ),
                      const SizedBox(height: 10),
                      const Text('Réservé aux comptes employés. Le mot de passe n’est pas conservé sur le téléphone.', textAlign: TextAlign.center, style: TextStyle(fontSize: 12)),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class Ticket {
  Ticket(this.data);
  final Map<String, dynamic> data;
  int get id => (data['id'] as num).toInt();
  String get numero => data['numero']?.toString() ?? '';
  String get statut => data['statut']?.toString() ?? '';
  String get categorie => data['categorie_nom']?.toString() ?? 'À régulariser';
  String get plat => data['plat_indicatif']?.toString() ?? '—';
  String get date => data['date_emission']?.toString() ?? '';
  int? get prix => data['prix_employe'] == null ? null : (data['prix_employe'] as num).toInt();
}

class TicketsPage extends StatefulWidget {
  const TicketsPage({super.key});

  @override
  State<TicketsPage> createState() => _TicketsPageState();
}

class _TicketsPageState extends State<TicketsPage> {
  Future<Map<String, dynamic>>? future;

  @override
  void initState() {
    super.initState();
    future = Api.tickets();
  }

  Future<void> _reload() async {
    setState(() => future = Api.tickets());
    try { await future; } catch (_) {}
  }

  Future<void> _logout() async {
    await Api.logout();
    if (!mounted) return;
    Navigator.of(context).pushAndRemoveUntil(MaterialPageRoute(builder: (_) => LoginPage(initialBase: Api.base)), (_) => false);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        backgroundColor: _green,
        foregroundColor: Colors.white,
        title: const Text('Mes tickets'),
        actions: [
          IconButton(onPressed: _reload, icon: const Icon(Icons.refresh), tooltip: 'Actualiser'),
          IconButton(onPressed: _logout, icon: const Icon(Icons.logout), tooltip: 'Quitter'),
        ],
      ),
      body: FutureBuilder<Map<String, dynamic>>(
        future: future,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return const Center(child: CircularProgressIndicator());
          }
          if (snapshot.hasError) {
            return Center(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Column(mainAxisSize: MainAxisSize.min, children: [
                  Text(snapshot.error.toString(), textAlign: TextAlign.center),
                  const SizedBox(height: 16),
                  FilledButton(onPressed: _reload, child: const Text('Réessayer')),
                ]),
              ),
            );
          }
          final body = snapshot.data ?? {};
          final societe = body['societe'] as Map<String, dynamic>?;
          final employee = body['employe'] as Map<String, dynamic>?;
          final tickets = ((body['tickets'] as List?) ?? []).map((e) => Ticket(e as Map<String, dynamic>)).toList();
          return RefreshIndicator(
            onRefresh: _reload,
            child: ListView(
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 28),
              children: [
                if (societe?['logo_disponible'] == true)
                  Image.network(Api.url('/logo').toString(), headers: Api.imageHeaders, height: 70, fit: BoxFit.contain, errorBuilder: (_, __, ___) => const SizedBox.shrink()),
                if ((societe?['nom']?.toString() ?? '').isNotEmpty)
                  Text(societe!['nom'].toString(), style: Theme.of(context).textTheme.titleLarge),
                Text('${employee?['nom'] ?? ''} · ${employee?['matricule'] ?? ''}', style: Theme.of(context).textTheme.titleMedium),
                const SizedBox(height: 4),
                const Text('Les tickets non consommés restent valables. Le paiement a lieu à la cantine.', style: TextStyle(fontSize: 12)),
                const SizedBox(height: 14),
                if (tickets.isEmpty) const Card(child: Padding(padding: EdgeInsets.all(20), child: Text('Aucun ticket pour le moment.'))),
                ...tickets.map((t) => _TicketCard(ticket: t, onRefresh: _reload)),
              ],
            ),
          );
        },
      ),
    );
  }
}

class _TicketCard extends StatelessWidget {
  const _TicketCard({required this.ticket, required this.onRefresh});
  final Ticket ticket;
  final Future<void> Function() onRefresh;

  Color get _color => switch (ticket.statut) {
        'DISPONIBLE' => _green,
        'UTILISE' => Colors.grey,
        _ => Colors.red,
      };

  @override
  Widget build(BuildContext context) {
    final date = ticket.date.length >= 10 ? ticket.date.substring(0, 10) : ticket.date;
    String shown = date;
    try {
      shown = DateFormat('dd/MM/yyyy').format(DateTime.parse(date));
    } catch (_) {}
    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: ListTile(
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
        leading: Icon(Icons.confirmation_num, color: _color, size: 34),
        title: Text(ticket.numero, style: const TextStyle(fontWeight: FontWeight.bold, letterSpacing: 2)),
        subtitle: Text('$shown · ${ticket.categorie}\n${ticket.prix == null ? 'Tarif à régulariser' : '${ticket.prix} FCFA à payer'} · ${ticket.statut}'),
        isThreeLine: true,
        trailing: const Icon(Icons.chevron_right),
        onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => TicketDetail(ticket: ticket, onRefresh: onRefresh))),
      ),
    );
  }
}

class TicketDetail extends StatelessWidget {
  const TicketDetail({super.key, required this.ticket, required this.onRefresh});
  final Ticket ticket;
  final Future<void> Function() onRefresh;

  @override
  Widget build(BuildContext context) {
    final qrUrl = Api.url('/tickets/${ticket.id}/qr');
    return Scaffold(
      appBar: AppBar(backgroundColor: _green, foregroundColor: Colors.white, title: Text(ticket.numero)),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          Card(
            child: Padding(
              padding: const EdgeInsets.all(18),
              child: Column(children: [
                Text(ticket.numero, style: const TextStyle(fontSize: 30, fontWeight: FontWeight.w800, letterSpacing: 3)),
                const SizedBox(height: 6),
                Text(ticket.categorie, style: Theme.of(context).textTheme.titleLarge),
                const SizedBox(height: 6),
                Text(ticket.prix == null ? 'Tarif à régulariser' : '${ticket.prix} FCFA à payer à la cantine', style: const TextStyle(fontWeight: FontWeight.bold)),
                const SizedBox(height: 4),
                Text('Plat indicatif : ${ticket.plat}'),
                const SizedBox(height: 4),
                Text('Délivré le ${ticket.date}'),
                const Divider(height: 30),
                if (ticket.statut == 'DISPONIBLE')
                  Image.network(
                    qrUrl.toString(),
                    headers: Api.imageHeaders,
                    width: 260,
                    height: 260,
                    errorBuilder: (_, __, ___) => const Padding(padding: EdgeInsets.all(16), child: Text('QR indisponible. Vérifiez la connexion, puis actualisez.')),
                  )
                else
                  Text('Ticket ${ticket.statut.toLowerCase()} : QR non affiché.', textAlign: TextAlign.center),
                const SizedBox(height: 10),
                const Text('Présentez cet écran à la caisse. Le repas n’est servi qu’après un seul encaissement validé par le serveur.', textAlign: TextAlign.center, style: TextStyle(fontSize: 12)),
              ]),
            ),
          ),
          ElevatedButton.icon(onPressed: () async { await onRefresh(); if (context.mounted) Navigator.of(context).pop(); }, icon: const Icon(Icons.refresh), label: const Text('Actualiser mes tickets')),
        ],
      ),
    );
  }
}
