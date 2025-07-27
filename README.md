# Sistema de Gestão - CAF

Sistema de gestão de medicamentos para farmácia com controle de estoque e usuários.

## Correções de Inconsistências Realizadas

### 1. **Correção de Bug Crítico**
- **Problema**: Linha 1000+ tinha condição `$_SESSION['operador_nivel'] == 'toxicity'` 
- **Correção**: Alterado para `$_SESSION['operador_nivel'] == 'operador'`
- **Impacto**: Corrigia erro que impedia operadores de ver movimentações pendentes

### 2. **Padronização de Tratamento de Erros**
- **Problema**: Mistura de `die()` e `showAlert()` para tratamento de erros
- **Correção**: Padronizado para usar `showAlert()` em todos os casos
- **Impacto**: Interface mais consistente e melhor experiência do usuário

### 3. **Organização de CSS**
- **Problema**: CSS duplicado (arquivo `styles.css` não utilizado + CSS inline)
- **Correção**: 
  - Movido todo CSS para arquivo `styles.css` externo
  - Removido CSS inline duplicado
  - Adicionado link para arquivo CSS no HTML
- **Impacto**: Melhor manutenibilidade e performance

### 4. **Melhorias de Segurança**
- **Adicionado**: Regeneração de ID de sessão no login
- **Adicionado**: Timeout de sessão (8 horas)
- **Adicionado**: Validação de tamanho de entrada
- **Adicionado**: Mensagem de sessão expirada
- **Adicionado**: Proteção contra navegação pelo histórico após logout
- **Adicionado**: Headers anti-cache para todas as páginas
- **Adicionado**: JavaScript para impedir uso do botão "voltar"
- **Adicionado**: Limpeza de dados de sessão no cliente
- **Adicionado**: Prevenção de refresh da página (F5, Ctrl+R)
- **Impacto**: Maior segurança contra ataques de sessão e acesso não autorizado

### 5. **Validação de Entrada Aprimorada**
- **Adicionado**: Validação de tamanho máximo para campos
- **Adicionado**: Validação de valores numéricos
- **Adicionado**: Mensagens de erro mais específicas
- **Impacto**: Prevenção de dados inválidos e melhor UX

### 6. **Melhorias Estéticas e UX**
- **Design Moderno**: Interface completamente redesenhada com gradientes e sombras
- **Fonte Inter**: Tipografia moderna e legível
- **Animações**: Efeitos de hover, transições e animações suaves
- **Página de Login**: Design atrativo com ícones e efeitos visuais
- **Responsividade**: Layout adaptável para todos os dispositivos
- **Efeitos Visuais**: Gradientes animados, sombras e efeitos de glassmorphism
- **Loading States**: Indicadores visuais de carregamento
- **Impacto**: Interface muito mais atrativa e profissional

## Estrutura do Sistema

### Funcionalidades Principais
- **Login/Logout**: Sistema de autenticação com níveis de acesso
- **Cadastro de Medicamentos**: Apenas para administradores
- **Gestão de Estoque**: Entradas, saídas e movimentações
- **Relatórios**: Exportação para Excel e impressão
- **Gestão de Operadores**: Cadastro e administração de usuários

### Níveis de Acesso
- **Administrador**: Acesso completo ao sistema
- **Operador**: Acesso limitado ao estoque e relatórios

### Tecnologias Utilizadas
- **Backend**: PHP 7.4+
- **Banco de Dados**: MySQL/MariaDB
- **Frontend**: HTML5, CSS3, JavaScript
- **Servidor**: Apache/XAMPP

## Instalação

1. Configure o XAMPP ou servidor Apache com PHP
2. Importe o banco de dados `farmacia.sql` (se existir)
3. Configure as credenciais do banco em `index.php`
4. Acesse via navegador

## Credenciais Padrão
- **Usuário**: brenoportto
- **Senha**: Sofia+123
- **Nível**: Administrador

## Melhorias Futuras Sugeridas

1. **Logs de Auditoria**: Registrar todas as ações dos usuários
2. **Backup Automático**: Sistema de backup do banco de dados
3. **API REST**: Interface para integração com outros sistemas
4. **Notificações**: Alertas de estoque baixo por email
5. **Relatórios Avançados**: Gráficos e análises estatísticas

## Suporte

Para dúvidas ou problemas, consulte a documentação ou entre em contato com o administrador do sistema. 