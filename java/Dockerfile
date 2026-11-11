FROM maven:3.9-eclipse-temurin-25 AS build
WORKDIR /build
COPY pom.xml ./
RUN mvn -B -ntp -q dependency:go-offline
COPY src/ src/
RUN mvn -B -ntp package -DskipTests

FROM eclipse-temurin:25-jre-alpine
RUN addgroup -S smartstate && adduser -S smartstate -G smartstate
WORKDIR /app
COPY --from=build /build/target/SmartState-1.0-SNAPSHOT.jar app.jar
USER smartstate
ENV TZ=GMT
EXPOSE 9000
HEALTHCHECK --interval=30s --timeout=5s --start-period=45s --retries=3 \
  CMD wget -q -O /dev/null http://127.0.0.1:9000/api/check || exit 1
ENTRYPOINT ["java", "-XX:MaxRAMPercentage=75.0", "-jar", "app.jar"]
