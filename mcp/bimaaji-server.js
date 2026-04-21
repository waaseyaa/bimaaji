import { execFile } from "node:child_process";
import { createRequire } from "node:module";
import { join } from "node:path";
import { pathToFileURL } from "node:url";
import { promisify } from "node:util";

const execFileAsync = promisify(execFile);
const projectRoot = process.cwd();
const graphScript = join(import.meta.dirname, "..", "bin", "bimaaji-graph");
const requireFromHere = createRequire(import.meta.url);

async function importDependency(modulePath, requireFromFiles = []) {
  try {
    return await import(modulePath);
  } catch (primaryError) {
    for (const fromFile of requireFromFiles) {
      try {
        const scopedRequire = createRequire(pathToFileURL(fromFile));
        const resolved = scopedRequire.resolve(modulePath);
        return await import(pathToFileURL(resolved).href);
      } catch {
        // Try the next candidate.
      }
    }

    const resolved = requireFromHere.resolve(modulePath);
    return await import(pathToFileURL(resolved).href).catch(() => {
      throw primaryError;
    });
  }
}

const dependencyRoots = [
  join(import.meta.dirname, "package.json"),
  join(projectRoot, "mcp/package.json"),
  join(projectRoot, "package.json"),
];

const { McpServer } = await importDependency(
  "@modelcontextprotocol/sdk/server/mcp.js",
  dependencyRoots,
);
const { StdioServerTransport } = await importDependency(
  "@modelcontextprotocol/sdk/server/stdio.js",
  dependencyRoots,
);
const { z } = await importDependency("zod", dependencyRoots);

async function fetchGraph() {
  const { stdout, stderr } = await execFileAsync("php", [graphScript], {
    cwd: projectRoot,
    maxBuffer: 50 * 1024 * 1024,
    env: { ...process.env },
  });

  if (stderr?.trim() && !stdout?.trim()) {
    throw new Error(stderr.trim());
  }

  return stdout.trim();
}

const server = new McpServer({
  name: "bimaaji",
  version: "1.0.0",
});

server.registerTool(
  "bimaaji_get_application_graph",
  {
    description:
      "Return the Bimaaji application graph for this Waaseyaa site as raw JSON.",
  },
  async () => {
    try {
      const graph = await fetchGraph();
      return { content: [{ type: "text", text: graph }] };
    } catch (err) {
      const message = err instanceof Error ? err.message : String(err);
      return {
        content: [{ type: "text", text: `Failed to run bimaaji graph: ${message}` }],
        isError: true,
      };
    }
  },
);

server.registerTool(
  "bimaaji_get_graph_section",
  {
    description:
      "Return a single section from the Bimaaji application graph by key (e.g. entities, admin).",
    inputSchema: {
      key: z.string().describe("Section key, e.g. entities, admin"),
    },
  },
  async ({ key }) => {
    try {
      const graph = JSON.parse(await fetchGraph());
      const section = graph?.sections?.[key];

      if (section === undefined) {
        const keys = graph?.sections ? Object.keys(graph.sections).sort().join(", ") : "(none)";
        return {
          content: [{ type: "text", text: `No section "${key}". Available: ${keys}` }],
          isError: true,
        };
      }

      return {
        content: [{ type: "text", text: JSON.stringify(section, null, 2) }],
      };
    } catch (err) {
      const message = err instanceof Error ? err.message : String(err);
      return {
        content: [{ type: "text", text: `Failed to read graph section: ${message}` }],
        isError: true,
      };
    }
  },
);

const transport = new StdioServerTransport();
await server.connect(transport);
